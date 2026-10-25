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
use Tests\TestCase;

class LedgerFlowReportTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): array
    {
        $this->travelTo(now()->setDate(2026, 10, 25)->startOfDay());
        $owner = User::factory()->create();
        $scope = Scope::factory()->create(['owner_id' => $owner->id]);
        $project = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $account = LedgerAccount::factory()->create(['project_id' => $project->id, 'opening_minor' => '10000']);
        $this->actingAs($owner)->withHeaders(['X-App-Request' => 'Zuratax', 'Accept' => 'application/json']);

        return [$scope, $project, $account, $owner, "/api/scopes/{$scope->id}/ledger"];
    }

    public function test_account_report_flag_defaults_to_false_and_is_versioned_validated_and_audited(): void
    {
        [, $project, $account, , $url] = $this->workspace();
        $this->getJson("$url/options")->assertOk()->assertJsonPath('data.accounts.0.exclude_from_reports', false);
        $this->postJson("$url/accounts", ['project_id' => $project->id, 'title' => 'Excluded', 'currency' => 'RUB', 'exclude_from_reports' => true])
            ->assertCreated()->assertJsonPath('data.exclude_from_reports', true);
        $this->patchJson("$url/accounts/{$account->id}", ['revision' => 1, 'exclude_from_reports' => true])
            ->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.exclude_from_reports', true);
        $this->assertDatabaseHas('ledger_accounts', ['id' => $account->id, 'exclude_from_reports' => true]);
        $this->assertTrue(ActivityLog::where('subject_id', $account->id)->latest('id')->first()->after['exclude_from_reports']);
        $this->patchJson("$url/accounts/{$account->id}", ['revision' => 1, 'exclude_from_reports' => false])->assertConflict();
        $this->patchJson("$url/accounts/{$account->id}", ['revision' => 2, 'exclude_from_reports' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('exclude_from_reports');
    }

    public function test_report_excludes_accounts_entirely_and_keeps_transfers_separate_from_income(): void
    {
        [, $project, $account, , $url] = $this->workspace();
        $excluded = LedgerAccount::factory()->create(['project_id' => $project->id, 'exclude_from_reports' => true, 'opening_minor' => '90000']);
        LedgerTransaction::factory()->create(['account_id' => $account->id, 'occurred_on' => '2026-09-30', 'status' => 'posted', 'kind' => 'expense', 'amount_minor' => '1000']);
        LedgerTransaction::factory()->create(['account_id' => $account->id, 'status' => 'posted', 'kind' => 'income', 'amount_minor' => '500']);
        LedgerTransaction::factory()->create(['account_id' => $account->id, 'target_account_id' => $excluded->id, 'status' => 'posted', 'kind' => 'transfer', 'amount_minor' => '200']);
        LedgerTransaction::factory()->create(['account_id' => $excluded->id, 'target_account_id' => $account->id, 'status' => 'posted', 'kind' => 'transfer', 'amount_minor' => '50']);
        foreach (['planned', 'void'] as $status) {
            LedgerTransaction::factory()->create(['account_id' => $account->id, 'status' => $status, 'kind' => 'expense', 'amount_minor' => '8888']);
        }
        LedgerTransaction::factory()->create(['account_id' => $account->id, 'status' => 'posted', 'occurred_on' => '2026-10-26', 'amount_minor' => '9999']);
        LedgerTransaction::factory()->create(['account_id' => $account->id, 'status' => 'posted', 'deleted_at' => now(), 'amount_minor' => '7777']);
        LedgerTransaction::factory()->create(['account_id' => $excluded->id, 'status' => 'posted', 'kind' => 'income', 'amount_minor' => '60000']);
        $result = $this->getJson("$url/report?start=2026-10&end=2026-10")->assertOk()->assertJsonCount(1, 'data.accounts')->assertJsonCount(1, 'data.months')
            ->assertJsonPath('data.months.0.opening_minor', '9000')->assertJsonPath('data.months.0.closing_minor', '9350')
            ->assertJsonPath('data.months.0.income', '500')->assertJsonPath('data.months.0.expense', '0')
            ->assertJsonPath('data.months.0.sent', '200')->assertJsonPath('data.months.0.received', '50');
        $this->assertStringNotContainsString($excluded->id, $result->getContent());
        $this->getJson("$url/report?start=2026-10&end=2026-10&account_id={$excluded->id}")->assertOk()->assertJsonCount(0, 'data.accounts');
        $this->getJson("$url/flow?start=2026-10&end=2026-10")->assertOk()->assertJsonCount(2, 'data.accounts');
        $this->patchJson("$url/accounts/{$excluded->id}", ['revision' => 1, 'exclude_from_reports' => false])->assertOk();
        $this->getJson("$url/report?start=2026-10&end=2026-10")->assertOk()->assertJsonCount(2, 'data.accounts');
    }

    public function test_flow_loads_more_than_one_journal_page_and_daily_balances_match_statements(): void
    {
        [, , $account, , $url] = $this->workspace();
        LedgerTransaction::factory()->count(45)->create(['account_id' => $account->id, 'status' => 'posted', 'amount_minor' => '10']);
        $flow = $this->getJson("$url/flow?start=2026-10&end=2026-10")->assertOk()->assertJsonCount(45, 'data.transactions')->json('data');
        $statement = $this->getJson("$url/accounts/{$account->id}/statement?month=2026-10")->assertOk()->json('data');
        $this->assertSame($statement['days'], $flow['daily'][$account->id]);
        $this->assertSame('9550', $flow['daily'][$account->id][24]['closing_minor']);
        $this->getJson("$url/flow?start=2026-11&end=2026-11")->assertOk()->assertJsonCount(0, 'data.daily');
    }

    public function test_report_uses_credit_interest_unknown_balances_and_separate_currencies(): void
    {
        [, $project, $account, , $url] = $this->workspace();
        $this->travelTo(now()->setDate(2026, 10, 2));
        $account->update(['type' => 'credit', 'opening_minor' => '-10000', 'interest_rate_bps' => 36500, 'interest_start_on' => '2026-10-01']);
        $unknown = LedgerAccount::factory()->create(['project_id' => $project->id, 'balance_is_known' => false]);
        LedgerTransaction::factory()->create(['account_id' => $unknown->id, 'status' => 'posted', 'kind' => 'income', 'occurred_on' => '2026-10-01', 'amount_minor' => '123']);
        $usd = LedgerAccount::factory()->create(['project_id' => $project->id, 'currency' => 'USD', 'opening_minor' => '999']);
        $rows = collect($this->getJson("$url/report?start=2026-10&end=2026-10")->assertOk()->json('data.months'))->keyBy('account_id');
        $this->assertSame('-201', $rows[$account->id]['interest']);
        $this->assertSame('-10201', $rows[$account->id]['closing_minor']);
        $this->assertNull($rows[$unknown->id]['closing_minor']);
        $this->assertSame('123', $rows[$unknown->id]['income']);
        $this->assertSame('USD', $rows[$usd->id]['currency']);
        $this->assertSame('999', $rows[$usd->id]['closing_minor']);
    }

    public function test_move_preserves_identity_content_category_and_updates_balances_with_a_history_entry(): void
    {
        [, $project, $account, $owner, $url] = $this->workspace();
        $destination = LedgerAccount::factory()->create(['project_id' => $project->id]);
        $category = LedgerCategory::factory()->create(['project_id' => $project->id, 'kind' => 'expense']);
        $transaction = LedgerTransaction::factory()->create(['account_id' => $account->id, 'category_id' => $category->id, 'note_md' => '# Preserve', 'status' => 'posted', 'amount_minor' => '250']);
        $this->postJson("$url/transactions/{$transaction->id}/move", ['revision' => 1, 'side' => 'source', 'account_id' => $destination->id, 'occurred_on' => '2026-10-24'])
            ->assertOk()->assertJsonPath('data.account_id', $destination->id)->assertJsonPath('data.revision', 2)->assertJsonPath('data.note_md', '# Preserve');
        $this->assertDatabaseHas('ledger_transactions', ['id' => $transaction->id, 'account_id' => $destination->id, 'category_id' => $category->id, 'amount_minor' => 250, 'created_by' => $owner->id]);
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $transaction->id, 'action' => 'ledger.transaction_moved']);
        $this->assertDatabaseCount('ledger_transactions', 1);
        $rows = collect($this->getJson("$url/report?start=2026-10&end=2026-10")->json('data.months'))->keyBy('account_id');
        $this->assertSame('10000', $rows[$account->id]['closing_minor']);
        $this->assertSame('-250', $rows[$destination->id]['closing_minor']);
        $this->postJson("$url/transactions/{$transaction->id}/move", ['revision' => 1, 'side' => 'source', 'account_id' => $account->id, 'occurred_on' => '2026-10-25'])->assertConflict();
    }

    public function test_transfer_moves_one_side_and_shared_date_atomically_and_rejects_invalid_destinations(): void
    {
        [, $project, $source, , $url] = $this->workspace();
        $target = LedgerAccount::factory()->create(['project_id' => $project->id]);
        $destination = LedgerAccount::factory()->create(['project_id' => $project->id]);
        $transfer = LedgerTransaction::factory()->create(['account_id' => $source->id, 'target_account_id' => $target->id, 'kind' => 'transfer', 'status' => 'posted']);
        $path = "$url/transactions/{$transfer->id}/move";
        $payload = ['revision' => 1, 'side' => 'target', 'account_id' => $destination->id, 'occurred_on' => '2026-10-24'];
        $this->postJson($path, $payload)->assertOk()->assertJsonPath('data.target_account_id', $destination->id)->assertJsonPath('data.account_id', $source->id)->assertJsonPath('data.occurred_on', '2026-10-24');
        $this->assertDatabaseCount('ledger_transactions', 1);
        $this->postJson($path, [...$payload, 'revision' => 2, 'account_id' => $source->id])->assertUnprocessable()->assertJsonValidationErrors('account_id');
        $target->update(['currency' => 'USD']);
        $this->postJson($path, [...$payload, 'revision' => 2, 'account_id' => $target->id])->assertUnprocessable();
        $target->update(['currency' => 'RUB', 'is_archived' => true]);
        $this->postJson($path, [...$payload, 'revision' => 2, 'account_id' => $target->id])->assertUnprocessable();
        $target->update(['is_archived' => false, 'balance_is_known' => false]);
        $this->postJson($path, [...$payload, 'revision' => 2, 'account_id' => $target->id])->assertUnprocessable();
        $otherProject = Project::factory()->create(['scope_id' => $project->scope_id]);
        $differentProject = LedgerAccount::factory()->create(['project_id' => $otherProject->id]);
        $this->postJson($path, [...$payload, 'revision' => 2, 'account_id' => $differentProject->id])->assertUnprocessable()->assertJsonValidationErrors('account_id');
        $foreign = LedgerAccount::factory()->create();
        $this->postJson($path, [...$payload, 'revision' => 2, 'account_id' => $foreign->id])->assertNotFound();
        $this->assertSame(2, $transfer->fresh()->revision);
    }

    public function test_report_and_flow_obey_project_scope_and_move_requires_both_transfer_sides(): void
    {
        [$scope, $project, $account, , $url] = $this->workspace();
        $otherProject = Project::factory()->create(['scope_id' => $scope->id]);
        $hidden = LedgerAccount::factory()->create(['project_id' => $otherProject->id, 'title' => 'Secret']);
        $transaction = LedgerTransaction::factory()->create(['account_id' => $hidden->id, 'target_account_id' => $account->id, 'kind' => 'transfer', 'status' => 'posted']);
        $user = User::factory()->create();
        $scope->members()->create(['user_id' => $user->id, 'role' => 'member', 'project_access_mode' => 'restricted', 'permissions' => ['allow' => ['ledger.view', 'ledger.write']]]);
        $project->members()->create(['user_id' => $user->id, 'is_active' => true]);
        $this->actingAs($user);
        $this->getJson("$url/report?start=2026-10&end=2026-10")->assertOk()->assertJsonCount(1, 'data.accounts')->assertJsonPath('data.months.0.received', '100');
        $this->getJson("$url/flow?start=2026-10&end=2026-10")->assertOk()->assertJsonPath('data.transactions.0.account', null)->assertJsonPath('data.transactions.0.account_id', null)->assertJsonPath('data.transactions.0.can_write', false);
        $this->postJson("$url/transactions/{$transaction->id}/move", ['revision' => 1, 'side' => 'target', 'account_id' => $account->id, 'occurred_on' => '2026-10-24'])->assertForbidden();
        $this->patchJson("$url/accounts/{$account->id}", ['revision' => 1, 'exclude_from_reports' => true])->assertForbidden();
        foreach (['flow', 'report'] as $view) {
            $this->getJson("$url/$view?start=2026-10&end=2026-10&account_id={$hidden->id}")->assertNotFound();
        }
        $foreign = LedgerAccount::factory()->create();
        $this->getJson("$url/report?start=2026-10&end=2026-10&account_id={$foreign->id}")->assertNotFound();
    }

    public function test_period_validation_rejects_invalid_reversed_and_unbounded_periods(): void
    {
        [, , , , $url] = $this->workspace();
        $this->getJson("$url/flow?start=2026-10&end=2026-13")->assertUnprocessable()->assertJsonValidationErrors('end');
        $this->getJson("$url/flow?start=2026-10&end=2026-09")->assertUnprocessable();
        $this->getJson("$url/flow?start=2026-01&end=2026-12")->assertUnprocessable()->assertJsonValidationErrors('end');
        $this->getJson("$url/report?start=2023-01&end=2026-10")->assertUnprocessable();
        $this->getJson("$url/report?start=2026-10&end=2026-11")->assertUnprocessable();
    }
}
