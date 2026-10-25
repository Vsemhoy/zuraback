<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\EntityLink;
use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\Project;
use App\Models\Scope;
use App\Models\Thing;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LedgerReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-02-02 12:00:00'));
        $owner = User::factory()->create();
        $scope = Scope::factory()->create(['owner_id' => $owner->id]);
        $project = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $account = LedgerAccount::factory()->create(['project_id' => $project->id, 'opening_minor' => 10000]);
        $this->actingAs($owner)->withHeaders(['X-App-Request' => 'Zuratax']);

        return [$scope, $owner, $account, "/api/scopes/{$scope->id}/ledger"];
    }

    private function payload(LedgerAccount $account, array $extra = []): array
    {
        return ['account_id' => $account->id, 'kind' => 'adjustment_out', 'amount' => '12.30', 'title' => 'Сверка',
            'occurred_on' => '2026-01-31', 'status' => 'posted', 'note_md' => 'Сверка с выпиской', 'idempotency_key' => (string) Str::uuid(), ...$extra];
    }

    public function test_corrections_change_balances_and_month_carry_but_not_income_or_expenses(): void
    {
        [, , $account, $url] = $this->workspace();

        $id = $this->postJson("$url/transactions", $this->payload($account))->assertCreated()->assertJsonPath('data.kind', 'adjustment_out')->json('data.id');

        $this->getJson("$url/accounts")->assertJsonPath('data.0.balance_minor', '8770')->assertJsonPath('data.0.balance_today_minor', '8770');
        $this->getJson("$url/accounts/{$account->id}/statement?month=2026-01")->assertJsonPath('data.closing_minor', '8770');
        $this->getJson("$url/accounts/{$account->id}/statement?month=2026-02")->assertJsonPath('data.opening_minor', '8770');
        $this->getJson("$url/transactions")->assertJsonPath('meta.totals.0.expense', '0')->assertJsonPath('meta.totals.0.income', '0')->assertJsonPath('meta.totals.0.adjustment_out', '1230');
        $this->patchJson("$url/transactions/$id", ['revision' => 1, 'amount' => '10.00', 'note_md' => 'Уточнено по чеку'])->assertOk()->assertJsonPath('data.revision', 2);
        $this->assertDatabaseHas('ledger_transactions', ['id' => $id, 'amount_minor' => 1000, 'note_md' => 'Уточнено по чеку']);
        $this->getJson("$url/transactions/$id/history")->assertJsonPath('data.0.before.amount_minor', '1230')->assertJsonPath('data.0.after.amount_minor', '1000');
        $this->patchJson("$url/transactions/$id", ['revision' => 1, 'amount' => '99.00'])->assertConflict();
        $this->assertDatabaseCount('activity_logs', 2);
    }

    public function test_missing_or_removed_reason_returns_422_without_changes(): void
    {
        [, , $account, $url] = $this->workspace();
        $this->postJson("$url/transactions", $this->payload($account, ['note_md' => null]))->assertUnprocessable()->assertJsonValidationErrors('note_md');
        $this->assertDatabaseCount('ledger_transactions', 0);
        $this->assertDatabaseCount('activity_logs', 0);
        $id = $this->postJson("$url/transactions", $this->payload($account))->json('data.id');

        $this->patchJson("$url/transactions/$id", ['revision' => 1, 'note_md' => ''])->assertUnprocessable()->assertJsonValidationErrors('note_md');

        $this->assertDatabaseHas('ledger_transactions', ['id' => $id, 'revision' => 1, 'note_md' => 'Сверка с выпиской']);
        $this->assertDatabaseCount('activity_logs', 1);
    }

    public function test_writer_without_manage_cannot_create_or_change_corrections(): void
    {
        [$scope, , $account, $url] = $this->workspace();
        $id = $this->postJson("$url/transactions", $this->payload($account))->json('data.id');
        $writer = User::factory()->create();
        $scope->members()->create(['user_id' => $writer->id, 'role' => 'member', 'is_active' => true, 'project_access_mode' => 'all',
            'permissions' => ['allow' => ['ledger.view', 'ledger.write'], 'deny' => ['ledger.manage']]]);
        $this->actingAs($writer);

        $this->postJson("$url/transactions", $this->payload($account))->assertForbidden();
        $this->patchJson("$url/transactions/$id", ['revision' => 1, 'title' => 'Bypass'])->assertForbidden();
        $this->getJson("$url/transactions/$id")->assertOk()->assertJsonPath('data.can_write', false);

        $this->assertDatabaseCount('ledger_transactions', 1);
        $this->assertDatabaseCount('activity_logs', 1);
    }

    public function test_agent_token_without_manage_cannot_use_owner_privileges_for_correction(): void
    {
        [$scope, $owner, $account] = $this->workspace();
        Sanctum::actingAs($owner, ['ledger.view', 'ledger.write']);

        $this->postJson("/api/agent/scopes/{$scope->id}/ledger/transactions", $this->payload($account))->assertForbidden();

        $this->assertDatabaseCount('ledger_transactions', 0);
    }

    public function test_unknown_balance_requires_explicit_amount_before_confirmation_and_blocks_operations(): void
    {
        [, , $account, $url] = $this->workspace();
        $account->update(['balance_is_known' => false, 'opening_minor' => 0]);
        LedgerTransaction::factory()->create(['account_id' => $account->id, 'amount_minor' => 500, 'occurred_on' => '2026-01-01', 'kind' => 'expense', 'status' => 'posted']);
        $this->getJson("$url/accounts")->assertJsonPath('data.0.balance_minor', null)->assertJsonPath('data.0.opening_locked', false)->assertJsonPath('data.0.can_write', false);
        $this->getJson("$url/accounts/{$account->id}/statement?month=2026-01")->assertUnprocessable()->assertJsonValidationErrors('account_id');
        $this->postJson("$url/transactions", $this->payload($account))->assertUnprocessable();

        $this->patchJson("$url/accounts/{$account->id}", ['revision' => 1, 'balance_is_known' => true])->assertUnprocessable()->assertJsonValidationErrors('opening_balance');
        $this->patchJson("$url/accounts/{$account->id}", ['revision' => 1, 'balance_is_known' => true, 'opening_balance' => '0.00'])
            ->assertOk()->assertJsonPath('data.balance_is_known', true)->assertJsonPath('data.balance_minor', '-500')->assertJsonPath('data.opening_locked', true);

        $log = ActivityLog::where('subject_id', $account->id)->sole();
        $this->assertFalse($log->before['balance_is_known']);
        $this->assertTrue($log->after['balance_is_known']);
        $this->assertDatabaseCount('ledger_transactions', 1);
    }

    public function test_cannot_bypass_fixed_opening_by_unconfirming_known_balance(): void
    {
        [, , $account, $url] = $this->workspace();
        LedgerTransaction::factory()->create(['account_id' => $account->id]);

        $this->patchJson("$url/accounts/{$account->id}", ['revision' => 1, 'balance_is_known' => false])->assertUnprocessable()->assertJsonValidationErrors('balance_is_known');
        $this->patchJson("$url/accounts/{$account->id}", ['revision' => 1, 'opening_balance' => '999.00'])->assertUnprocessable()->assertJsonValidationErrors('opening_balance');

        $this->assertDatabaseHas('ledger_accounts', ['id' => $account->id, 'opening_minor' => 10000, 'balance_is_known' => 1, 'revision' => 1]);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_correction_is_idempotent_and_delete_restore_reverses_it_once(): void
    {
        [, , $account, $url] = $this->workspace();
        $payload = $this->payload($account, ['kind' => 'adjustment_in', 'amount' => '1.00']);
        $id = $this->postJson("$url/transactions", $payload)->assertCreated()->json('data.id');
        $this->postJson("$url/transactions", $payload)->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('ledger_transactions', 1);

        $this->deleteJson("$url/transactions/$id", ['revision' => 1])->assertOk();
        $this->getJson("$url/accounts")->assertJsonPath('data.0.balance_today_minor', '10000');
        $this->postJson("$url/transactions/$id/restore", ['revision' => 2])->assertOk();
        $this->getJson("$url/accounts")->assertJsonPath('data.0.balance_today_minor', '10100');

        $this->assertDatabaseCount('activity_logs', 3);
    }

    public function test_related_equipment_links_require_both_financial_and_equipment_access(): void
    {
        [$scope, $owner, $account, $url] = $this->workspace();
        $transaction = LedgerTransaction::factory()->create(['account_id' => $account->id]);
        $thing = Thing::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $foreign = Thing::factory()->create();
        foreach ([$thing, $foreign] as $item) {
            EntityLink::create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'source_type' => 'ledger_transaction', 'source_id' => $transaction->id,
                'target_type' => 'thing', 'target_id' => $item->id, 'relation' => 'related']);
        }

        $this->getJson("$url/transactions/{$transaction->id}")->assertOk()->assertJsonCount(1, 'data.related_things')->assertJsonPath('data.related_things.0.id', $thing->id);
        $this->getJson("/api/scopes/{$scope->id}/links")->assertOk()->assertJsonCount(0, 'data');
        $viewer = User::factory()->create();
        $scope->members()->create(['user_id' => $viewer->id, 'role' => 'member', 'project_access_mode' => 'all', 'is_active' => true,
            'permissions' => ['allow' => ['ledger.view'], 'deny' => ['equip.view']]]);
        $this->actingAs($viewer)->getJson("$url/transactions/{$transaction->id}")->assertOk()->assertJsonCount(0, 'data.related_things');
        $this->actingAs(User::factory()->create())->getJson("$url/transactions/{$transaction->id}")->assertForbidden();
    }

    public function test_unauthenticated_correction_request_is_refused(): void
    {
        [, , $account, $url] = $this->workspace();
        $this->app['auth']->forgetGuards();
        $this->app['auth']->guard()->logout();

        $this->postJson("$url/transactions", $this->payload($account))->assertUnauthorized();
        $this->assertDatabaseCount('ledger_transactions', 0);
    }
}
