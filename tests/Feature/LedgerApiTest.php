<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\LedgerAccount;
use App\Models\LedgerCategory;
use App\Models\LedgerTransaction;
use App\Models\Project;
use App\Models\Scope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LedgerApiTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): array
    {
        $owner = User::factory()->create();
        $scope = Scope::factory()->create(['owner_id' => $owner->id]);
        $project = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $account = LedgerAccount::factory()->create(['project_id' => $project->id]);
        $this->actingAs($owner)->withHeaders(['X-App-Request' => 'Zuratax', 'Accept' => 'application/json']);

        return [$scope, $project, $account, $owner, "/api/scopes/{$scope->id}/ledger"];
    }

    private function payload(LedgerAccount $account, array $extra = []): array
    {
        return [...['account_id' => $account->id, 'kind' => 'expense', 'amount' => '123.45', 'occurred_on' => '2026-10-23', 'status' => 'posted', 'title' => 'Оплата сервиса', 'idempotency_key' => (string) Str::uuid()], ...$extra];
    }

    private function balances(string $url): array
    {
        return collect($this->getJson("$url/accounts?archived=1")->assertOk()->json('data'))->keyBy('id')->all();
    }

    public function test_accounts_require_project_and_use_exact_opening_balance_with_explicit_credit_terms(): void
    {
        [$scope, $project, , $owner, $url] = $this->workspace();

        $this->postJson("$url/accounts", ['title' => 'Касса', 'currency' => 'RUB'])->assertUnprocessable()->assertJsonValidationErrors('project_id');
        $credit = $this->postJson("$url/accounts", ['project_id' => $project->id, 'title' => 'Кредит', 'currency' => 'RUB', 'type' => 'credit', 'interest_rate_bps' => 2350, 'interest_start_on' => '2026-10-01'])
            ->assertCreated()->assertJsonPath('data.interest_rate_bps', 2350)->json('data.id');
        $this->assertDatabaseHas('ledger_accounts', ['id' => $credit, 'scope_id' => $scope->id, 'project_id' => $project->id, 'created_by' => $owner->id, 'interest_start_on' => '2026-10-01']);
        $id = $this->postJson("$url/accounts", ['project_id' => $project->id, 'title' => 'Касса', 'currency' => 'RUB', 'type' => 'cash', 'opening_balance' => '100.25'])->assertCreated()->assertJsonPath('data.balance_minor', '10025')->json('data.id');

        $this->assertDatabaseHas('ledger_accounts', ['id' => $id, 'scope_id' => $scope->id, 'project_id' => $project->id, 'created_by' => $owner->id, 'creator_name' => $owner->name, 'opening_minor' => 10025]);
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $id, 'action' => 'ledger.account_created']);
    }

    public function test_exact_minor_units_and_planned_or_void_records_do_not_affect_cash(): void
    {
        [, , $account, , $url] = $this->workspace();

        $this->postJson("$url/transactions", $this->payload($account, ['kind' => 'income', 'amount' => '0.10']))->assertCreated()->assertJsonPath('data.amount_minor', '10');
        $this->postJson("$url/transactions", $this->payload($account, ['kind' => 'income', 'amount' => '0.20']))->assertCreated();
        $this->postJson("$url/transactions", $this->payload($account, ['status' => 'planned', 'amount' => '900']))->assertCreated();
        $this->postJson("$url/transactions", $this->payload($account, ['status' => 'void', 'amount' => '800']))->assertCreated();

        $this->assertSame('30', $this->balances($url)[$account->id]['balance_minor']);
        $this->getJson("$url/transactions")->assertJsonPath('meta.totals.0.income', '30')->assertJsonPath('meta.totals.0.expense', '0');
        $this->getJson("$url/options")->assertJsonPath('data.accounts.0.balance_minor', '30');
    }

    public function test_partial_patches_preserve_content_creator_and_money_and_stale_writes_return_409(): void
    {
        [, , $account, $owner, $url] = $this->workspace();
        $id = $this->postJson("$url/transactions", $this->payload($account, ['note_md' => "# Счёт\n\n```js\nconsole.log(1)\n```\n\nКонец"]))->json('data.id');

        $this->patchJson("$url/transactions/$id", ['revision' => 1, 'title' => 'Уточнено'])->assertOk()->assertJsonPath('data.amount', '123.45')->assertJsonPath('data.revision', 2)->assertJsonPath('data.created_by', $owner->id);
        $this->getJson("$url/transactions/$id")->assertJsonPath('data.note_md', "# Счёт\n\n```js\nconsole.log(1)\n```\n\nКонец");
        $this->patchJson("$url/transactions/$id", ['revision' => 1, 'amount' => '10'])->assertConflict();
        $this->deleteJson("$url/transactions/$id", ['revision' => 1])->assertConflict();

        $this->assertDatabaseHas('ledger_transactions', ['id' => $id, 'amount_minor' => 12345, 'title' => 'Уточнено']);
        $this->getJson("$url/transactions/$id/history")->assertOk()->assertJsonPath('data.0.before.title', 'Оплата сервиса')->assertJsonPath('data.0.after.title', 'Уточнено');
    }

    public function test_identical_create_retries_return_same_operation_and_reused_key_with_different_payload_returns_409(): void
    {
        [, , $account, , $url] = $this->workspace();
        $payload = $this->payload($account);
        $id = $this->postJson("$url/transactions", $payload)->assertCreated()->json('data.id');

        $this->postJson("$url/transactions", $payload)->assertCreated()->assertJsonPath('data.id', $id);
        $this->postJson("$url/transactions", [...$payload, 'amount' => '100'])->assertConflict();

        $this->assertDatabaseCount('ledger_transactions', 1);
        $this->assertDatabaseCount('activity_logs', 1);
        $this->assertSame('-12345', $this->balances($url)[$account->id]['balance_minor']);
    }

    public function test_transfers_are_single_atomic_records_and_delete_restore_both_legs(): void
    {
        [, $project, $source, , $url] = $this->workspace();
        $target = LedgerAccount::factory()->create(['project_id' => $project->id]);
        $payload = $this->payload($source, ['kind' => 'transfer', 'target_account_id' => $target->id, 'amount' => '100.25']);

        $id = $this->postJson("$url/transactions", $payload)->assertCreated()->json('data.id');

        $this->assertDatabaseCount('ledger_transactions', 1);
        $balances = $this->balances($url);
        $this->assertSame('-10025', $balances[$source->id]['balance_minor']);
        $this->assertSame('10025', $balances[$target->id]['balance_minor']);
        $this->getJson("$url/transactions")->assertJsonCount(1, 'data')->assertJsonPath('meta.totals.0.expense', '0')->assertJsonPath('meta.totals.0.income', '0');
        $this->deleteJson("$url/transactions/$id", ['revision' => 1])->assertOk();
        $this->assertSame('0', $this->balances($url)[$source->id]['balance_minor']);
        $this->assertSame('0', $this->balances($url)[$target->id]['balance_minor']);
        $this->assertSoftDeleted('ledger_transactions', ['id' => $id]);
        $this->getJson("$url/transactions?trash=1")->assertJsonCount(1, 'data')->assertJsonPath('data.0.revision', 2);
        $this->postJson("$url/transactions/$id/restore", ['revision' => 2])->assertOk()->assertJsonPath('data.revision', 3);
        $this->assertSame('10025', $this->balances($url)[$target->id]['balance_minor']);
    }

    public function test_invalid_transfer_targets_and_currency_mismatch_leave_no_half_operation(): void
    {
        [, $project, $source, , $url] = $this->workspace();
        $otherCurrency = LedgerAccount::factory()->create(['project_id' => $project->id, 'currency' => 'USD']);
        $foreign = LedgerAccount::factory()->create();

        $this->postJson("$url/transactions", $this->payload($source, ['kind' => 'transfer', 'target_account_id' => $source->id]))->assertUnprocessable()->assertJsonValidationErrors('target_account_id');
        $this->postJson("$url/transactions", $this->payload($source, ['kind' => 'transfer', 'target_account_id' => $otherCurrency->id]))->assertUnprocessable();
        $this->postJson("$url/transactions", $this->payload($source, ['kind' => 'transfer', 'target_account_id' => $foreign->id]))->assertNotFound();

        $this->assertDatabaseCount('ledger_transactions', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_project_revocation_removes_creator_access_but_keeps_records_and_author_for_colleagues(): void
    {
        [$scope, $project, , $owner, $url] = $this->workspace();
        $author = User::factory()->create(['name' => 'Бывший автор']);
        $project->update(['created_by' => $author->id]);
        $scope->members()->create(['user_id' => $author->id, 'role' => 'member', 'project_access_mode' => 'restricted', 'permissions' => ['allow' => ['ledger.view', 'ledger.write', 'ledger.manage']]]);
        $membership = $project->members()->create(['user_id' => $author->id, 'is_active' => true]);
        $this->actingAs($author);
        $account = $this->postJson("$url/accounts", ['project_id' => $project->id, 'title' => 'Проектный счёт', 'currency' => 'RUB'])->assertCreated()->json('data');
        $id = $this->postJson("$url/transactions", [...$this->payload(LedgerAccount::findOrFail($account['id']))])->assertCreated()->json('data.id');
        $membership->update(['is_active' => false]);

        $this->getJson("$url/options")->assertOk()->assertJsonCount(0, 'data.accounts')->assertJsonCount(0, 'data.projects');
        $this->getJson("$url/transactions")->assertOk()->assertJsonCount(0, 'data')->assertJsonCount(0, 'meta.totals');
        $this->getJson("$url/transactions/$id")->assertNotFound();
        $this->patchJson("$url/transactions/$id", ['revision' => 1, 'title' => 'Bypass'])->assertNotFound();
        $this->actingAs($owner);
        $author->delete();

        $this->getJson("$url/transactions/$id")->assertOk()->assertJsonPath('data.created_by', $author->id)->assertJsonPath('data.creator_name', 'Бывший автор');
        $this->assertDatabaseHas('ledger_accounts', ['id' => $account['id'], 'created_by' => $author->id]);
        $this->assertDatabaseHas('ledger_transactions', ['id' => $id, 'deleted_at' => null]);
    }

    public function test_one_sided_project_access_redacts_transfer_counterparty_and_cannot_edit_either_leg(): void
    {
        [$scope, $leftProject, $left, , $url] = $this->workspace();
        $rightProject = Project::factory()->create(['scope_id' => $scope->id]);
        $right = LedgerAccount::factory()->create(['project_id' => $rightProject->id]);
        $id = $this->postJson("$url/transactions", $this->payload($left, ['kind' => 'transfer', 'target_account_id' => $right->id, 'amount' => '15']))->json('data.id');
        $viewer = User::factory()->create();
        $scope->members()->create(['user_id' => $viewer->id, 'role' => 'member', 'project_access_mode' => 'restricted', 'permissions' => ['allow' => ['ledger.view', 'ledger.write', 'ledger.manage']]]);
        $rightProject->members()->create(['user_id' => $viewer->id, 'is_active' => true]);
        $this->actingAs($viewer);

        $this->getJson("$url/transactions/$id")->assertOk()->assertJsonPath('data.account', null)->assertJsonPath('data.account_id', null)->assertJsonPath('data.target_account_id', $right->id)->assertJsonPath('data.can_write', false);
        $this->getJson("$url/transactions")->assertJsonCount(1, 'data')->assertJsonPath('meta.totals.0.sent', '0')->assertJsonPath('meta.totals.0.received', '1500');
        $this->patchJson("$url/transactions/$id", ['revision' => 1, 'amount' => '100'])->assertForbidden();
        $this->deleteJson("$url/transactions/$id", ['revision' => 1])->assertForbidden();
        $this->getJson("$url/transactions/$id/history")->assertForbidden();
        $this->getJson("$url/accounts?project_id={$leftProject->id}")->assertJsonCount(0, 'data');
        $this->assertSame('1500', $this->balances($url)[$right->id]['balance_minor']);
    }

    public function test_account_archiving_preserves_history_and_prevents_new_operations(): void
    {
        [, , $account, , $url] = $this->workspace();
        $id = $this->postJson("$url/transactions", $this->payload($account))->json('data.id');

        $this->patchJson("$url/accounts/{$account->id}", ['revision' => 1, 'project_id' => $account->project_id, 'currency' => 'USD'])->assertUnprocessable()->assertJsonValidationErrors(['project_id', 'currency']);
        $this->patchJson("$url/accounts/{$account->id}", ['revision' => 1, 'opening_balance' => '10'])->assertUnprocessable();
        $this->patchJson("$url/accounts/{$account->id}", ['revision' => 1, 'is_archived' => true])->assertOk();
        $this->postJson("$url/transactions", $this->payload($account))->assertUnprocessable();
        $this->getJson("$url/transactions/$id")->assertOk();
        $this->getJson("$url/accounts")->assertJsonCount(0, 'data');
        $this->assertSame('-12345', $this->balances($url)[$account->id]['balance_minor']);
    }

    public function test_categories_are_project_scoped_and_archived_categories_cannot_be_newly_assigned(): void
    {
        [, $project, $account, , $url] = $this->workspace();
        $category = $this->postJson("$url/categories", ['project_id' => $project->id, 'title' => 'Обслуживание', 'kind' => 'expense'])->assertCreated()->json('data');
        $foreign = LedgerCategory::factory()->create();

        $this->postJson("$url/transactions", $this->payload($account, ['category_id' => $foreign->id]))->assertUnprocessable();
        $this->postJson("$url/transactions", $this->payload($account, ['category_id' => $category['id']]))->assertCreated();
        $this->patchJson("$url/categories/{$category['id']}", ['revision' => 1, 'kind' => 'income'])->assertUnprocessable();
        $this->patchJson("$url/categories/{$category['id']}", ['revision' => 1, 'is_archived' => true])->assertOk();
        $this->postJson("$url/transactions", $this->payload($account, ['category_id' => $category['id']]))->assertUnprocessable();
    }

    public function test_totals_cover_all_pages_and_keep_currencies_separate(): void
    {
        [, $project, $account, , $url] = $this->workspace();
        LedgerTransaction::factory()->count(45)->create(['account_id' => $account->id, 'status' => 'posted', 'amount_minor' => '10']);
        $usd = LedgerAccount::factory()->create(['project_id' => $project->id, 'currency' => 'USD']);
        LedgerTransaction::factory()->create(['account_id' => $usd->id, 'status' => 'posted', 'amount_minor' => '25']);

        $response = $this->getJson("$url/transactions")->assertOk()->assertJsonCount(40, 'data')->assertJsonPath('meta.total', 46);

        $totals = collect($response->json('meta.totals'))->keyBy('currency');
        $this->assertSame('450', $totals['RUB']['expense']);
        $this->assertSame('25', $totals['USD']['expense']);
        $this->getJson("$url/transactions?account_id={$account->id}")->assertJsonPath('meta.total', 45);
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_amount_returns_422_without_money_movements(mixed $amount): void
    {
        [, , $account, , $url] = $this->workspace();

        $this->postJson("$url/transactions", $this->payload($account, ['amount' => $amount]))->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->assertDatabaseCount('ledger_transactions', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public static function invalidAmounts(): array
    {
        return [['0'], ['-1'], ['1.001'], ['1e3'], ['10000000000.00'], ['word']];
    }

    public function test_ledger_capabilities_module_switch_and_cross_scope_ids_are_enforced(): void
    {
        [$scope, , $account, $owner, $url] = $this->workspace();
        $foreign = LedgerAccount::factory()->create();
        $foreignTransaction = LedgerTransaction::factory()->create(['account_id' => $foreign->id]);
        $this->getJson("$url/transactions/{$foreignTransaction->id}")->assertNotFound();
        $this->postJson("$url/transactions", $this->payload($foreign))->assertNotFound();
        $viewer = User::factory()->create();
        $scope->members()->create(['user_id' => $viewer->id, 'role' => 'member', 'project_access_mode' => 'all']);
        $this->actingAs($viewer)->getJson("$url/options")->assertForbidden();
        $this->actingAs($owner);
        $scope->update(['settings' => ['modules' => ['enabled' => ['ledger' => false]]]]);
        $this->getJson("$url/options")->assertForbidden();
        $this->postJson("$url/transactions", $this->payload($account))->assertForbidden();
    }

    public function test_agent_tokens_need_financial_abilities_even_for_scope_admin(): void
    {
        [$scope, , $account] = $this->workspace();
        $agent = User::factory()->create(['type' => 'agent']);
        $scope->members()->create(['user_id' => $agent->id, 'role' => 'admin', 'project_access_mode' => 'all']);
        $url = "/api/agent/scopes/{$scope->id}/ledger";
        Sanctum::actingAs($agent, ['ledger.view']);

        $this->getJson("$url/accounts")->assertOk();
        $this->postJson("$url/transactions", $this->payload($account))->assertForbidden();
        Sanctum::actingAs($agent, ['ledger.view', 'ledger.write']);
        $this->postJson("$url/transactions", $this->payload($account))->assertCreated();
        $this->postJson("$url/accounts", ['project_id' => $account->project_id, 'title' => 'No manage', 'currency' => 'RUB'])->assertForbidden();
    }

    public function test_contractor_traces_do_not_bypass_current_financial_project_access(): void
    {
        [$scope, , $account, $owner, $url] = $this->workspace();
        $id = $this->postJson("$url/transactions", $this->payload($account))->assertCreated()->json('data.id');
        $traces = "/api/scopes/{$scope->id}/contractors/{$owner->id}/activity";
        $this->getJson($traces)->assertOk()->assertJsonPath('data.0.subject_id', $id)->assertJsonPath('data.0.after.amount_minor', '12345');
        $manager = User::factory()->create();
        $scope->members()->create(['user_id' => $manager->id, 'role' => 'admin', 'project_access_mode' => 'none']);

        $response = $this->actingAs($manager)->getJson($traces)->assertOk()
            ->assertJsonPath('data.0.action', 'ledger.restricted')
            ->assertJsonPath('data.0.subject_id', null)
            ->assertJsonPath('data.0.after', ['restricted' => true]);

        $this->assertStringNotContainsString($account->id, $response->getContent());
        $this->assertStringNotContainsString('12345', $response->getContent());
    }

    public function test_financial_api_trace_payload_is_redacted_without_destroying_stored_audit(): void
    {
        [$scope, , $account, $owner] = $this->workspace();
        $log = ActivityLog::query()->create([
            'scope_id' => $scope->id, 'actor_id' => $owner->id, 'subject_type' => 'agent_api',
            'subject_id' => $owner->id, 'action' => 'agent.api.post', 'after' => ['status' => 201, 'duration_ms' => 10],
            'context' => ['path' => "/api/agent/scopes/{$scope->id}/ledger/transactions", 'token_id' => 7,
                'payload' => ['account_id' => $account->id, 'amount' => '765.43'],
                'query' => ['account_id' => $account->id], 'route_parameters' => ['account' => $account->id]],
        ]);

        $response = $this->getJson("/api/scopes/{$scope->id}/contractors/{$owner->id}/activity")->assertOk()
            ->assertJsonPath('data.0.after.status', 201)->assertJsonPath('data.0.context.token_id', 7)
            ->assertJsonPath('data.0.context.restricted', true);

        $this->assertStringNotContainsString($account->id, $response->getContent());
        $this->assertStringNotContainsString('765.43', $response->getContent());
        $this->assertSame('765.43', $log->fresh()->context['payload']['amount']);
    }

    public function test_unauthenticated_financial_api_returns_401(): void
    {
        $scope = Scope::factory()->create();

        $this->withHeaders(['X-App-Request' => 'Zuratax'])->getJson("/api/scopes/{$scope->id}/ledger/accounts")->assertUnauthorized();
    }
}
