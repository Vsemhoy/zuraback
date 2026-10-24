<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\ExploitationEntry;
use App\Models\LegacyImportRecord;
use App\Models\Scope;
use App\Models\ScopeMember;
use App\Models\Thing;
use App\Models\ThingComposition;
use App\Models\ThingType;
use App\Models\User;
use App\Services\EquipAccessService;
use App\Services\LegacyEquipImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class LegacyEquipImportTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $owner = User::factory()->create();
        $scope = Scope::factory()->create(['owner_id' => $owner->id, 'is_private' => true]);
        $sourceOwner = Str::lower((string) Str::ulid());
        $row = fn (array $extra): array => ['id' => Str::lower((string) Str::ulid()), 'user_id' => $sourceOwner,
            'created_at' => '2025-01-01 10:00:00', 'updated_at' => '2025-02-01 11:00:00', 'deleted_at' => null, ...$extra];
        $category = $row(['name' => 'Техника']);
        $subCategory = $row(['name' => 'Компьютеры', 'parent_id' => $category['id']]);
        $place = $row(['name' => 'Дом']);
        $shelf = $row(['name' => 'Полка', 'parent_id' => $place['id']]);
        $thing = $row(['name' => 'Компьютер', 'entity_type' => 'asset', 'qty' => '1.00', 'unit' => 'шт', 'visibility' => 'private',
            'current_status' => 'active', 'current_location_id' => $shelf['id'], 'category_id' => $subCategory['id'],
            'description' => 'Описание', 'purchase_price' => 12345, 'serial_no' => 'SERIAL-1', 'purchase_date' => '2024-06-01']);
        $child = $row(['name' => 'Блок питания', 'entity_type' => 'item', 'qty' => null, 'visibility' => 'private',
            'current_status' => 'sold', 'parent_id' => $thing['id'], 'current_location_id' => $place['id']]);
        $contact = Contact::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'name' => 'Сервис', 'visibility' => 'private']);
        $oldContact = Str::lower((string) Str::ulid());
        LegacyImportRecord::create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'batch_id' => (string) Str::ulid(),
            'source' => 'home.zuratax.com', 'source_type' => 'contact', 'source_id' => $oldContact, 'target_type' => 'contact',
            'target_id' => $contact->id, 'source_hash' => str_repeat('0', 64), 'snapshot' => ['id' => $oldContact]]);
        $work = $row(['thing_id' => $thing['id'], 'event_type' => 'repaired', 'event_kind' => 'work', 'note' => 'Ремонт',
            'content_md' => "# Ремонт\n```js\nx()\n```\nПосле кода", 'details' => '{"result":"Не починено","address":"Сервис"}',
            'status' => 23, 'priority' => 12, 'performer_contact_id' => $oldContact, 'occurred_at' => '2025-01-10',
            'part_cost' => 5050, 'labor_cost' => 999, 'time_self_min' => 999, 'from_location_id' => $place['id'], 'to_location_id' => $shelf['id']]);
        $resource = $row(['thing_id' => $child['id'], 'event_type' => 'repaired', 'event_kind' => 'consumption', 'note' => 'Расход',
            'content_md' => null, 'details' => '{"resource_type":"Жидкость","amount":2.5,"unit":"л"}', 'status' => 22,
            'priority' => 15, 'occurred_at' => '2025-01-11']);
        $content = $row(['source_module' => 'exploiter', 'source_id' => $resource['id'], 'field' => 'content', 'kind' => 'markdown', 'is_primary' => 1, 'body_md' => '**Источник MD**']);
        $alternate = $row(['source_module' => 'exploiter', 'source_id' => $work['id'], 'field' => 'content', 'kind' => 'markdown', 'is_primary' => 1, 'body_md' => 'Архивный контент, не заменяет content_md']);
        $timer = $row(['source_module' => 'exploiter', 'source_id' => $work['id'], 'time_type' => 'self', 'duration_min' => 35]);
        $ledger = $row(['exploiter_event_id' => $work['id'], 'cost_type' => 'part']);
        $expense = $row(['thing_id' => $thing['id'], 'register_id' => $work['id'], 'transaction_id' => $ledger['id'], 'amount' => 5050]);
        $snapshot = ['schema' => 1, 'source' => 'home.zuratax.com', 'owner_id' => $sourceOwner, 'owner_hash' => hash('sha256', Str::lower($owner->email)),
            'missing_category_ids' => [], 'things' => [$thing, $child], 'locations' => [$shelf, $place],
            'categories' => [$subCategory, $category], 'registers' => [$work, $resource], 'contents' => [$content, $alternate],
            'timer_entries' => [$timer], 'ledger_refs' => [$ledger], 'expenses' => [$expense],
            'event_refs' => [$row(['thing_id' => $thing['id'], 'exploiter_event_id' => $work['id']])]];

        return [$scope, $owner, $snapshot, $contact];
    }

    public function test_dry_run_reconciles_every_node_without_writing(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $typesBefore = ThingType::count();

        $result = app(LegacyEquipImportService::class)->import($scope, $snapshot);

        $this->assertSame(16, $result['planned']);
        $this->assertSame(0, $result['created']);
        foreach (['things', 'thing_compositions', 'exploitation_entries', 'activity_logs', 'ledger_transactions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('thing_types', $typesBefore);
        $this->assertDatabaseCount('legacy_import_records', 1);
    }

    public function test_import_preserves_categories_locations_assemblies_contacts_content_and_money_units_without_ledger(): void
    {
        [$scope, $owner, $snapshot, $contact] = $this->fixture();
        Event::fake(['eloquent.created: '.Thing::class, 'eloquent.created: '.ExploitationEntry::class,
            'eloquent.created: '.ThingComposition::class, 'eloquent.created: '.ActivityLog::class]);

        $result = app(LegacyEquipImportService::class)->import($scope, $snapshot, true);

        $maps = $result['maps'];
        $thing = Thing::findOrFail($maps['things'][$snapshot['things'][0]['id']]);
        $child = $maps['things'][$snapshot['things'][1]['id']];
        $place = $maps['locations'][$snapshot['locations'][1]['id']];
        $shelf = $maps['locations'][$snapshot['locations'][0]['id']];
        $work = ExploitationEntry::findOrFail($maps['registers'][$snapshot['registers'][0]['id']]);
        $resource = ExploitationEntry::findOrFail($maps['registers'][$snapshot['registers'][1]['id']]);
        $this->assertSame('123.45', $thing->purchase_cost);
        $this->assertSame('sold', Thing::findOrFail($child)->characteristics['legacy_status']);
        $this->assertArrayNotHasKey('quantity', Thing::findOrFail($child)->characteristics);
        $this->assertDatabaseHas('things', ['id' => $thing->id, 'owner_kind' => 'employee', 'owner_id' => $owner->id, 'container_kind' => 'logical', 'department_id' => null]);
        $this->assertDatabaseHas('thing_types', ['id' => $maps['categories'][$snapshot['categories'][0]['id']], 'parent_id' => $maps['categories'][$snapshot['categories'][1]['id']]]);
        $this->assertDatabaseHas('thing_compositions', ['parent_id' => $place, 'child_id' => $shelf, 'kind' => 'physical', 'physical_active' => 1]);
        $this->assertDatabaseHas('thing_compositions', ['parent_id' => $shelf, 'child_id' => $thing->id, 'kind' => 'physical']);
        $this->assertDatabaseHas('thing_compositions', ['parent_id' => $thing->id, 'child_id' => $child, 'kind' => 'logical', 'physical_active' => null]);
        $this->assertDatabaseHas('thing_compositions', ['parent_id' => $place, 'child_id' => $child, 'physical_active' => 1]);
        $this->assertSame('planned', $work->status);
        $this->assertSame('Сервис', $work->performer_name);
        $this->assertNull($work->performer_id);
        $this->assertSame('50.50', $work->part_cost);
        $this->assertSame('9.99', $work->labor_cost);
        $this->assertSame(35, $work->own_minutes);
        $this->assertSame('Не починено', $work->result_md);
        $this->assertStringStartsWith($snapshot['registers'][0]['content_md'], $work->content_md);
        $this->assertStringContainsString('Заблокировано', $work->content_md);
        $this->assertStringStartsWith('**Источник MD**', $resource->content_md);
        $this->assertSame('consumption', $resource->kind);
        $this->assertSame('2.5000', $resource->quantity);
        $history = ActivityLog::where('subject_id', $thing->id)->sole();
        $this->assertSame($contact->id, $history->after['performer_contact_id']);
        $this->assertSame($shelf, $history->after['to_location_id']);
        $archive = LegacyImportRecord::where('source_type', 'equip_exploitation')->where('target_id', $work->id)->sole();
        $this->assertSame($snapshot['ledger_refs'], $archive->snapshot['ledger_refs']);
        $this->assertSame($snapshot['event_refs'], $archive->snapshot['event_refs']);
        $this->assertSame($snapshot['contents'][1], $archive->snapshot['contents'][0]);
        $this->assertStringNotContainsString('Сервис', $archive->getRawOriginal('snapshot'));
        $this->assertDatabaseCount('ledger_transactions', 0);
        $this->assertDatabaseCount('ledger_accounts', 0);
        Event::assertNothingDispatched();
    }

    public function test_rerun_preserves_user_edits_closed_relations_and_deleted_entries_without_duplicates(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $service = app(LegacyEquipImportService::class);
        $service->import($scope, $snapshot, true);
        Thing::query()->firstOrFail()->update(['name' => 'Новое имя']);
        ExploitationEntry::query()->firstOrFail()->delete();
        ThingComposition::query()->firstOrFail()->update(['active' => null, 'physical_active' => null, 'ended_at' => '2026-01-01']);

        $repeat = $service->import($scope, $snapshot, true);

        $this->assertSame(0, $repeat['created']);
        $this->assertSame(16, array_sum($repeat['counts']['skip']));
        $this->assertDatabaseCount('things', 4);
        $this->assertDatabaseCount('legacy_import_records', 17);
        $this->assertDatabaseHas('things', ['name' => 'Новое имя']);
        $this->assertSame(1, ExploitationEntry::count());
        $this->assertSame(3, ThingComposition::where('active', 1)->count());
    }

    public function test_original_id_in_other_scope_is_never_reused_or_modified(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $other = Thing::factory()->create(['id' => $snapshot['things'][0]['id'], 'name' => 'Не трогать']);

        $result = app(LegacyEquipImportService::class)->import($scope, $snapshot, true);

        $this->assertNotSame($other->id, $result['maps']['things'][$other->id]);
        $this->assertDatabaseHas('things', ['id' => $other->id, 'name' => 'Не трогать']);
    }

    public function test_ambiguous_existing_name_aborts_instead_of_creating_a_duplicate(): void
    {
        [$scope, $owner, $snapshot] = $this->fixture();
        Thing::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'name' => 'Компьютер']);

        try {
            app(LegacyEquipImportService::class)->import($scope, $snapshot, true);
            $this->fail('Unproven matches require review.');
        } catch (LogicException) {
            $this->assertDatabaseCount('things', 1);
            $this->assertDatabaseCount('legacy_import_records', 1);
        }
    }

    public function test_missing_category_and_incomplete_resource_are_preserved_without_invented_values(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $missing = 'old-broken-category-reference';
        $snapshot['missing_category_ids'] = [$missing];
        $snapshot['things'][0]['category_id'] = $missing;
        $snapshot['registers'][1]['details'] = '{"amount":2.5}';

        $result = app(LegacyEquipImportService::class)->import($scope, $snapshot, true);

        $thing = Thing::findOrFail($result['maps']['things'][$snapshot['things'][0]['id']]);
        $resource = ExploitationEntry::findOrFail($result['maps']['registers'][$snapshot['registers'][1]['id']]);
        $this->assertSame($missing, $thing->characteristics['category_reference']);
        $this->assertSame('note', $resource->kind);
        $this->assertNull($resource->unit);
        $this->assertNull($resource->resource_name);
        $this->assertStringContainsString('Значения не додумывались', $resource->content_md);
        $this->assertSame(['missing_categories' => 1, 'blocked_entries' => 1, 'incomplete_resources' => 1], $result['warnings']);
    }

    public static function invalidSnapshots(): array
    {
        return ['cycle' => ['cycle'], 'missing_place' => ['missing_place'], 'mixed_owner' => ['mixed_owner'],
            'duplicate' => ['duplicate'], 'missing_contact' => ['missing_contact'], 'primary_content' => ['primary_content'],
            'public_thing' => ['public_thing'], 'unknown_status' => ['unknown_status'], 'undeclared_category' => ['undeclared_category'],
            'category_cycle' => ['category_cycle'], 'location_cycle' => ['location_cycle'], 'false_missing_category' => ['false_missing_category'],
            'duplicate_category_name' => ['duplicate_category_name']];
    }

    #[DataProvider('invalidSnapshots')]
    public function test_invalid_graph_or_source_aborts_entire_batch(string $case): void
    {
        [$scope, , $snapshot] = $this->fixture();
        switch ($case) {
            case 'category_cycle':
                $snapshot['categories'][1]['parent_id'] = $snapshot['categories'][0]['id'];
                break;
            case 'location_cycle':
                $snapshot['locations'][1]['parent_id'] = $snapshot['locations'][0]['id'];
                break;
            case 'false_missing_category':
                $snapshot['missing_category_ids'] = [$snapshot['categories'][0]['id']];
                break;
            case 'duplicate_category_name':
                $snapshot['categories'][1]['name'] = $snapshot['categories'][0]['name'];
                break;
            case 'cycle': $snapshot['things'][0]['parent_id'] = $snapshot['things'][1]['id'];
                break;
            case 'missing_place': $snapshot['things'][0]['current_location_id'] = (string) Str::ulid();
                break;
            case 'mixed_owner': $snapshot['registers'][0]['user_id'] = (string) Str::ulid();
                break;
            case 'duplicate': $snapshot['things'][] = $snapshot['things'][0];
                break;
            case 'missing_contact': $snapshot['registers'][0]['performer_contact_id'] = (string) Str::ulid();
                break;
            case 'primary_content': $snapshot['contents'][] = [...$snapshot['contents'][0], 'id' => (string) Str::ulid()];
                break;
            case 'public_thing': $snapshot['things'][0]['visibility'] = 'public';
                break;
            case 'unknown_status': $snapshot['registers'][0]['status'] = 99;
                break;
            case 'undeclared_category': $snapshot['things'][0]['category_id'] = 'missing';
                break;
        }

        try {
            app(LegacyEquipImportService::class)->import($scope, $snapshot, true);
            $this->fail('Invalid snapshots must be refused.');
        } catch (LogicException) {
            $this->assertDatabaseCount('things', 0);
            $this->assertDatabaseCount('legacy_import_records', 1);
        }
    }

    public function test_changed_source_refuses_overwriting_existing_import(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $service = app(LegacyEquipImportService::class);
        $service->import($scope, $snapshot, true);
        $snapshot['things'][0]['name'] = 'Изменился источник';

        try {
            $service->import($scope, $snapshot, true);
            $this->fail('Changed source must be reconciled explicitly.');
        } catch (LogicException) {
            $this->assertDatabaseHas('things', ['name' => 'Компьютер']);
            $this->assertDatabaseCount('legacy_import_records', 17);
        }
    }

    public function test_database_failure_rolls_back_native_rows_and_import_journal(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $typesBefore = ThingType::count();
        Event::listen('eloquent.creating: '.LegacyImportRecord::class, function ($record): void {
            if ($record->source_type === 'equip_exploitation') {
                throw new RuntimeException('Synthetic persistence failure');
            }
        });

        try {
            app(LegacyEquipImportService::class)->import($scope, $snapshot, true);
            $this->fail('Failure must abort the transaction.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('things', 0);
            $this->assertDatabaseCount('thing_types', $typesBefore);
            $this->assertDatabaseCount('thing_compositions', 0);
            $this->assertDatabaseCount('exploitation_entries', 0);
            $this->assertDatabaseCount('legacy_import_records', 1);
        }
    }

    public function test_private_owner_and_enabled_modules_are_required(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $scope->update(['is_private' => false]);

        try {
            app(LegacyEquipImportService::class)->import($scope, $snapshot, true);
            $this->fail('Public destination must be refused.');
        } catch (LogicException) {
            $this->assertDatabaseCount('things', 0);
        }
        $scope->update(['is_private' => true]);
        $snapshot['owner_hash'] = str_repeat('0', 64);
        $this->expectException(LogicException::class);
        app(LegacyEquipImportService::class)->import($scope, $snapshot, true);
    }

    public function test_import_does_not_grant_department_access_to_other_members(): void
    {
        [$scope, $owner, $snapshot] = $this->fixture();
        $member = User::factory()->create();
        ScopeMember::create(['scope_id' => $scope->id, 'user_id' => $member->id, 'role' => 'member', 'is_active' => true,
            'permissions' => ['allow' => ['equip.view'], 'deny' => ['equip.access_all']]]);
        app(LegacyEquipImportService::class)->import($scope, $snapshot, true);
        $thing = Thing::where('scope_id', $scope->id)->firstOrFail();
        $access = app(EquipAccessService::class);

        $this->assertTrue($access->thing($owner, $scope, $thing));
        $this->assertFalse($access->thing($member, $scope, $thing));
    }

    public function test_command_requires_confirmed_hash_and_never_prints_private_payload(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $path = tempnam(sys_get_temp_dir(), 'equip-import-test-');
        $bytes = json_encode($snapshot, JSON_THROW_ON_ERROR);
        file_put_contents($path, $bytes);
        chmod($path, 0600);
        try {
            $this->artisan('legacy:import-equip', ['snapshot' => $path, '--scope' => $scope->id, '--commit' => true])->assertFailed();
            $this->assertDatabaseCount('things', 0);
            $this->artisan('legacy:import-equip', ['snapshot' => $path, '--scope' => $scope->id, '--commit' => true, '--expect-sha256' => hash('sha256', $bytes)])
                ->doesntExpectOutputToContain('Сервис')->doesntExpectOutputToContain('Компьютер')->doesntExpectOutputToContain('После кода')->assertSuccessful();
            $this->assertDatabaseCount('things', 4);
        } finally {
            unlink($path);
        }
    }

    public function test_corrupt_mapping_cannot_write_into_another_scope(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $service = app(LegacyEquipImportService::class);
        $service->import($scope, $snapshot, true);
        $other = Thing::factory()->create(['name' => 'Чужая вещь']);
        DB::table('legacy_import_records')->where('source_type', 'equip_thing')->where('source_id', $snapshot['things'][0]['id'])->update(['target_id' => $other->id]);

        try {
            $service->import($scope, $snapshot, true);
            $this->fail('Cross-scope mapping must be refused.');
        } catch (LogicException) {
            $this->assertDatabaseHas('things', ['id' => $other->id, 'name' => 'Чужая вещь']);
            $this->assertDatabaseCount('things', 5);
        }
    }
}
