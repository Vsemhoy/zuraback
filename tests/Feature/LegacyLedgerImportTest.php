<?php

namespace Tests\Feature;

use App\Models\EntityLink;
use App\Models\ExploitationEntry;
use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\LegacyImportRecord;
use App\Models\Project;
use App\Models\Scope;
use App\Models\Thing;
use App\Models\User;
use App\Services\LegacyLedgerImportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LegacyLedgerImportTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-03 12:00:00'));
        $owner = User::factory()->create();
        $scope = Scope::factory()->create(['owner_id' => $owner->id, 'is_private' => true]);
        $sourceOwner = Str::lower((string) Str::ulid());
        $row = fn (array $extra): array => ['id' => Str::lower((string) Str::ulid()), 'user_id' => $sourceOwner,
            'created_at' => '2025-01-01 10:00:00', 'updated_at' => '2025-02-01 10:00:00', ...$extra];
        $layer = $row(['type' => 'base']);
        $account = $row(['name' => 'Касса', 'type' => 'cash', 'currency' => 'RUB', 'opening_balance' => 10000,
            'is_archived' => false, 'sort_order' => 0, 'layer_id' => $layer['id']]);
        $bank = $row(['name' => 'Карта', 'type' => 'card', 'currency' => 'RUB', 'opening_balance' => 0,
            'is_archived' => false, 'sort_order' => 1, 'layer_id' => $layer['id']]);
        $parent = $row(['name' => 'Дом', 'parent_id' => null, 'is_archived' => false]);
        $category = $row(['name' => 'Ремонт', 'parent_id' => $parent['id'], 'is_archived' => false]);
        $transaction = $row(['account_id' => $account['id'], 'layer_id' => $layer['id'], 'flow_kind' => 'expense', 'amount' => 1000,
            'occurred_at' => '2026-01-01', 'month_key' => '2026-01', 'is_negative' => false, 'is_disabled' => false, 'is_pinned' => false,
            'status' => 'cleared', 'title' => 'Ремонт', 'note' => 'Исходная заметка', 'deleted_at' => null, 'category_id' => $category['id'],
            'target_account_id' => null, 'original_transaction_id' => null, 'exploiter_event_id' => null, 'linked_entity_type' => null, 'linked_entity_id' => null]);
        $snapshot = ['schema' => 1, 'source' => 'home.zuratax.com', 'owner_id' => $sourceOwner,
            'owner_hash' => hash('sha256', Str::lower(trim($owner->email))), 'layers' => [$layer], 'accounts' => [$account, $bank],
            'categories' => [$parent, $category], 'transactions' => [$transaction], 'month_totals' => [['closing' => 99999999]],
            'groups' => [], 'contents' => [], 'transaction_tags' => [], 'tags' => []];

        return [$scope, $owner, $snapshot];
    }

    public function test_dry_run_is_read_only_and_commit_imports_operations_not_cached_totals(): void
    {
        [$scope, $owner, $snapshot] = $this->fixture();
        $importer = app(LegacyLedgerImportService::class);

        $dry = $importer->import($scope, $snapshot);

        $this->assertTrue($dry['dry_run']);
        $this->assertSame(6, $dry['planned']);
        foreach (['projects', 'ledger_accounts', 'ledger_transactions', 'legacy_import_records'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }

        $result = $importer->import($scope, $snapshot, true);

        $this->assertSame(6, $result['created']);
        $this->assertSame(2, $result['verified_accounts']);
        $this->assertSame(1, $result['warnings']['ignored_month_totals']);
        $this->assertDatabaseHas('projects', ['id' => $result['maps']['project'], 'scope_id' => $scope->id, 'created_by' => $owner->id,
            'key' => 'FIN', 'visibility' => 'private', 'include_in_reports' => 0]);
        $this->assertDatabaseHas('ledger_accounts', ['id' => $result['maps']['accounts'][$snapshot['accounts'][0]['id']], 'opening_minor' => 10000]);
        $this->assertDatabaseHas('ledger_categories', ['title' => 'Дом / Ремонт']);
        $this->assertDatabaseCount('ledger_transactions', 1);
        $this->assertDatabaseHas('ledger_transactions', ['id' => $result['maps']['transactions'][$snapshot['transactions'][0]['id']],
            'amount_minor' => 1000, 'occurred_on' => '2026-01-01', 'kind' => 'expense', 'status' => 'posted']);
        $archive = LegacyImportRecord::where('source_type', 'ledger_transaction')->sole();
        $this->assertSame($snapshot['transactions'][0], $archive->snapshot['row']);
        $this->assertStringNotContainsString('Исходная заметка', DB::table('legacy_import_records')->where('id', $archive->id)->value('snapshot'));
        $this->assertArrayNotHasKey('snapshot', $archive->toArray());
    }

    public function test_transfer_pair_is_one_atomic_payment_using_actual_accounts_not_stale_pointers(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $out = [...$snapshot['transactions'][0], 'flow_kind' => 'transfer_out', 'target_account_id' => $snapshot['accounts'][0]['id']];
        $in = [...$out, 'id' => Str::lower((string) Str::ulid()), 'flow_kind' => 'transfer_in', 'account_id' => $snapshot['accounts'][1]['id'],
            'original_transaction_id' => $out['id'], 'target_account_id' => $snapshot['accounts'][1]['id']];
        $snapshot['transactions'] = [$out, $in];

        $result = app(LegacyLedgerImportService::class)->import($scope, $snapshot, true);

        $this->assertSame(1, $result['native_transactions']);
        $this->assertSame(1, $result['warnings']['stale_transfer_references']);
        $this->assertSame($result['maps']['transactions'][$out['id']], $result['maps']['transactions'][$in['id']]);
        $this->assertDatabaseCount('ledger_transactions', 1);
        $this->assertDatabaseHas('ledger_transactions', ['kind' => 'transfer', 'account_id' => $result['maps']['accounts'][$out['account_id']],
            'target_account_id' => $result['maps']['accounts'][$in['account_id']], 'amount_minor' => 1000]);
    }

    public function test_orphan_account_remains_archived_unknown_and_does_not_invent_zero_balance(): void
    {
        [$scope, $owner, $snapshot] = $this->fixture();
        $snapshot['transactions'][0]['account_id'] = Str::lower((string) Str::ulid());

        $result = app(LegacyLedgerImportService::class)->import($scope, $snapshot, true);

        $unknown = LedgerAccount::where('title', 'Неустановленный счёт')->sole();
        $this->assertFalse($unknown->balance_is_known);
        $this->assertTrue($unknown->is_archived);
        $this->assertSame(1, $result['warnings']['orphan_accounts']);
        $this->actingAs($owner)->withHeaders(['X-App-Request' => 'Zuratax'])->getJson("/api/scopes/{$scope->id}/ledger/accounts?archived=1")
            ->assertOk()->assertJsonFragment(['id' => $unknown->id, 'balance_minor' => null, 'balance_today_minor' => null, 'can_write' => false]);
    }

    public function test_repeat_does_not_duplicate_overwrite_manual_edits_or_resurrect_deleted_payments(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $importer = app(LegacyLedgerImportService::class);
        $first = $importer->import($scope, $snapshot, true);
        $transaction = LedgerTransaction::findOrFail($first['maps']['transactions'][$snapshot['transactions'][0]['id']]);
        $transaction->update(['title' => 'Ручная правка', 'amount_minor' => 700]);
        $transaction->delete();

        $repeat = $importer->import($scope, $snapshot, true);

        $this->assertSame(0, $repeat['created']);
        $this->assertSame(0, $repeat['planned']);
        $this->assertDatabaseCount('ledger_transactions', 1);
        $this->assertDatabaseHas('ledger_transactions', ['id' => $transaction->id, 'amount_minor' => 700, 'title' => 'Ручная правка']);
        $this->assertSoftDeleted($transaction);
    }

    public function test_credit_and_adjustments_keep_original_integer_amounts_without_fake_income_or_expenses(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $snapshot['accounts'][0] = [...$snapshot['accounts'][0], 'type' => 'credit', 'opening_balance' => -10000, 'interest_rate' => 36500, 'interest_start' => '2026-01-01'];
        $snapshot['transactions'][0]['flow_kind'] = 'adjustment';
        $snapshot['transactions'][0]['amount'] = 5100;

        $result = app(LegacyLedgerImportService::class)->import($scope, $snapshot, true);

        $this->assertDatabaseHas('ledger_accounts', ['id' => $result['maps']['accounts'][$snapshot['accounts'][0]['id']], 'type' => 'credit',
            'interest_rate_bps' => 36500, 'interest_start_on' => '2026-01-01', 'opening_minor' => -10000]);
        $this->assertDatabaseHas('ledger_transactions', ['kind' => 'adjustment_in', 'amount_minor' => 5100]);
        $this->assertDatabaseCount('ledger_transactions', 1);
    }

    public function test_links_existing_equipment_and_many_payments_to_one_exploitation_without_double_costs(): void
    {
        [$scope, $owner, $snapshot] = $this->fixture();
        $thing = Thing::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $entry = ExploitationEntry::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'thing_id' => $thing->id, 'part_cost' => '99.00']);
        $oldThing = Str::lower((string) Str::ulid());
        $oldEntry = Str::lower((string) Str::ulid());
        foreach ([['equip_thing', $oldThing, 'thing', $thing->id], ['equip_exploitation', $oldEntry, 'exploitation_entry', $entry->id]] as [$kind, $old, $type, $target]) {
            LegacyImportRecord::create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'batch_id' => (string) Str::ulid(), 'source' => 'home.zuratax.com',
                'source_type' => $kind, 'source_id' => $old, 'target_type' => $type, 'target_id' => $target, 'source_hash' => str_repeat('a', 64), 'snapshot' => []]);
        }
        $snapshot['transactions'][0] = [...$snapshot['transactions'][0], 'exploiter_event_id' => $oldEntry, 'linked_entity_type' => 'stuffer.thing', 'linked_entity_id' => $oldThing, 'cost_type' => 'part'];
        $snapshot['transactions'][] = [...$snapshot['transactions'][0], 'id' => Str::lower((string) Str::ulid()), 'amount' => 500, 'cost_type' => 'labor'];

        $result = app(LegacyLedgerImportService::class)->import($scope, $snapshot, true);

        $this->assertSame(2, $result['native_transactions']);
        $this->assertDatabaseCount('ledger_transactions', 2);
        $this->assertDatabaseHas('ledger_transactions', ['exploitation_entry_id' => $entry->id, 'cost_type' => 'parts', 'amount_minor' => 1000]);
        $this->assertDatabaseHas('ledger_transactions', ['exploitation_entry_id' => $entry->id, 'cost_type' => 'labor', 'amount_minor' => 500]);
        $this->assertSame('99.00', $entry->fresh()->part_cost);
        $this->assertDatabaseCount('entity_links', 2);
        $this->assertSame($thing->id, EntityLink::first()->target_id);
    }

    public static function invalidSnapshots(): array
    {
        return ['mixed owner' => ['owner'], 'duplicate identity' => ['duplicate'], 'category cycle' => ['cycle'], 'bad transfer' => ['transfer'],
            'other layer' => ['layer'], 'wrong owner hash' => ['hash'], 'missing active exploitation' => ['entry'], 'unsupported content' => ['contents']];
    }

    #[DataProvider('invalidSnapshots')]
    public function test_invalid_source_rolls_back_every_target(string $case): void
    {
        [$scope, , $snapshot] = $this->fixture();
        switch ($case) {
            case 'owner': $snapshot['transactions'][0]['user_id'] = (string) Str::ulid();
                break;
            case 'duplicate': $snapshot['transactions'][] = $snapshot['transactions'][0];
                break;
            case 'cycle': $snapshot['categories'][0]['parent_id'] = $snapshot['categories'][1]['id'];
                break;
            case 'transfer': $snapshot['transactions'][0]['flow_kind'] = 'transfer_out';
                break;
            case 'layer': $snapshot['transactions'][0]['layer_id'] = (string) Str::ulid();
                break;
            case 'hash': $snapshot['owner_hash'] = str_repeat('0', 64);
                break;
            case 'entry': $snapshot['transactions'][0]['exploiter_event_id'] = (string) Str::ulid();
                break;
            case 'contents': $snapshot['contents'] = [['id' => 'unsupported']];
                break;
        }

        try {
            app(LegacyLedgerImportService::class)->import($scope, $snapshot, true);
            $this->fail('Unsafe source was accepted.');
        } catch (LogicException) {
            foreach (['projects', 'ledger_accounts', 'ledger_categories', 'ledger_transactions', 'legacy_import_records'] as $table) {
                $this->assertDatabaseCount($table, 0);
            }
        }
    }

    public function test_changed_source_is_not_overwritten(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $importer = app(LegacyLedgerImportService::class);
        $importer->import($scope, $snapshot, true);
        $snapshot['transactions'][0]['amount'] = 1234;

        try {
            $importer->import($scope, $snapshot, true);
            $this->fail('Changed source was silently overwritten.');
        } catch (LogicException) {
            $this->assertDatabaseCount('ledger_transactions', 1);
            $this->assertDatabaseHas('ledger_transactions', ['amount_minor' => 1000]);
        }
    }

    public function test_cross_scope_mapping_or_occupied_fin_prevents_any_writes(): void
    {
        [$scope, $owner, $snapshot] = $this->fixture();
        $other = Scope::factory()->create(['owner_id' => $owner->id, 'is_private' => true]);
        $importer = app(LegacyLedgerImportService::class);
        $importer->import($scope, $snapshot, true);

        try {
            $importer->import($other, $snapshot, true);
            $this->fail('A source was duplicated in another scope.');
        } catch (LogicException) {
            $this->assertSame(0, LedgerAccount::where('scope_id', $other->id)->count());
            $this->assertDatabaseCount('projects', 1);
        }
    }

    public function test_unregistered_fin_is_never_silently_merged_or_overwritten(): void
    {
        [$scope, $owner, $snapshot] = $this->fixture();
        $project = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'key' => 'FIN']);

        try {
            app(LegacyLedgerImportService::class)->import($scope, $snapshot, true);
            $this->fail('An occupied FIN project was silently adopted.');
        } catch (LogicException) {
            $this->assertModelExists($project);
            $this->assertDatabaseCount('projects', 1);
            $this->assertDatabaseCount('ledger_accounts', 0);
            $this->assertDatabaseCount('legacy_import_records', 0);
        }
    }

    public function test_failure_after_inserts_rolls_back_entire_financial_import(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $snapshot['accounts'][0] = [...$snapshot['accounts'][0], 'type' => 'credit', 'opening_balance' => -2147483648,
            'interest_rate' => 100000, 'interest_start' => '1900-01-01'];

        try {
            app(LegacyLedgerImportService::class)->import($scope, $snapshot, true);
            $this->fail('An overflowing credit model was accepted.');
        } catch (ValidationException) {
            foreach (['projects', 'ledger_accounts', 'ledger_categories', 'ledger_transactions', 'legacy_import_records'] as $table) {
                $this->assertDatabaseCount($table, 0);
            }
        }
    }

    public function test_commit_requires_hash_without_logging_source_contents(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $file = tempnam(sys_get_temp_dir(), 'ledger-test-');
        try {
            file_put_contents($file, json_encode($snapshot, JSON_THROW_ON_ERROR));
            chmod($file, 0600);

            $this->artisan('legacy:import-ledger', ['snapshot' => $file, '--scope' => $scope->id, '--commit' => true])
                ->expectsOutputToContain('exact SHA-256')->assertFailed();
            $this->assertDatabaseCount('ledger_accounts', 0);
            $this->artisan('legacy:import-ledger', ['snapshot' => $file, '--scope' => $scope->id])->assertSuccessful();
            $this->assertDatabaseCount('ledger_transactions', 0);
        } finally {
            unlink($file);
        }
    }
}
