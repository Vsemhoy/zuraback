<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\Project;
use App\Models\Scope;
use App\Models\User;
use App\Services\LedgerInterestService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LedgerInterestTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-03 12:00:00'));
        $owner = User::factory()->create();
        $scope = Scope::factory()->create(['owner_id' => $owner->id]);
        $project = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $this->actingAs($owner)->withHeaders(['X-App-Request' => 'Zuratax', 'Accept' => 'application/json']);

        return [$scope, $project, $owner, "/api/scopes/{$scope->id}/ledger"];
    }

    private function credit(Project $project, array $extra = []): LedgerAccount
    {
        return LedgerAccount::factory()->create([...['project_id' => $project->id, 'type' => 'credit', 'opening_minor' => '-10000',
            'interest_rate_bps' => 36500, 'interest_start_on' => '2026-01-01'], ...$extra]);
    }

    public function test_creates_credit_with_explicit_terms_and_separate_read_only_interest(): void
    {
        [, $project, , $url] = $this->workspace();

        $response = $this->postJson("$url/accounts", ['project_id' => $project->id, 'title' => 'Кредитный счёт', 'type' => 'credit', 'currency' => 'RUB',
            'opening_balance' => '0.00', 'interest_rate_bps' => 36500, 'interest_start_on' => '2026-01-01']);

        $id = $response->assertCreated()->assertJsonPath('data.balance_minor', '0')->assertJsonPath('data.balance_today_minor', '0')
            ->assertJsonPath('data.principal_today_minor', '0')->assertJsonPath('data.interest_minor', '0')->assertJsonPath('data.balance_as_of', '2026-01-03')->json('data.id');
        $this->assertDatabaseHas('ledger_accounts', ['id' => $id, 'interest_rate_bps' => 36500, 'interest_start_on' => '2026-01-01']);
        $this->assertDatabaseCount('ledger_transactions', 0);
        $this->assertDatabaseCount('activity_logs', 1);
    }

    public function test_credit_rejects_nonzero_opening_and_does_not_silently_discard_it(): void
    {
        [, $project, , $url] = $this->workspace();
        foreach (['-100.00', '100.00'] as $opening) {
            $this->postJson("$url/accounts", ['project_id' => $project->id, 'title' => 'Credit', 'type' => 'credit', 'currency' => 'RUB',
                'opening_balance' => $opening, 'interest_rate_bps' => 36500, 'interest_start_on' => '2026-01-01'])
                ->assertUnprocessable()->assertJsonValidationErrors('opening_balance')
                ->assertJsonPath('errors.opening_balance.0', 'Кредитный счёт открывается с нулевым остатком. Долг оформляется проведённой операцией.');
        }
        $this->assertDatabaseCount('ledger_accounts', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_credit_defaults_to_zero_when_opening_is_omitted(): void
    {
        [, $project, , $url] = $this->workspace();
        $id = $this->postJson("$url/accounts", ['project_id' => $project->id, 'title' => 'Credit', 'type' => 'credit', 'currency' => 'RUB',
            'interest_rate_bps' => 36500, 'interest_start_on' => '2026-01-01'])
            ->assertCreated()->assertJsonPath('data.balance_today_minor', '0')->json('data.id');
        $this->assertDatabaseHas('ledger_accounts', ['id' => $id, 'opening_minor' => 0]);
    }

    public function test_legacy_credit_opening_correction_is_versioned_audited_and_preserves_real_debt(): void
    {
        [, $project, , $url] = $this->workspace();
        $account = $this->credit($project);
        $transaction = LedgerTransaction::factory()->create(['account_id' => $account->id, 'kind' => 'expense', 'amount_minor' => '5000',
            'occurred_on' => '2026-01-02', 'status' => 'posted']);
        $this->patchJson("$url/accounts/{$account->id}", ['revision' => 2, 'opening_balance' => '0.00'])->assertConflict();
        $this->patchJson("$url/accounts/{$account->id}", ['revision' => 1, 'opening_balance' => '-1.00'])->assertUnprocessable();

        $this->patchJson("$url/accounts/{$account->id}", ['revision' => 1, 'opening_balance' => '0.00'])
            ->assertOk()->assertJsonPath('data.opening_minor', '0')->assertJsonPath('data.revision', 2)
            ->assertJsonPath('data.principal_today_minor', '-5000')->assertJsonPath('data.interest_minor', '-101')
            ->assertJsonPath('data.balance_today_minor', '-5101');

        $this->assertDatabaseHas('ledger_accounts', ['id' => $account->id, 'opening_minor' => 0, 'revision' => 2]);
        $this->assertDatabaseHas('ledger_transactions', ['id' => $transaction->id, 'amount_minor' => 5000, 'revision' => 1]);
        $this->assertDatabaseCount('ledger_transactions', 1);
        $log = ActivityLog::where('subject_id', $account->id)->sole();
        $this->assertSame('-10000', $log->before['opening_minor']);
        $this->assertSame('0', $log->after['opening_minor']);
    }

    public function test_credit_opening_correction_requires_manage_permission(): void
    {
        [$scope, $project, , $url] = $this->workspace();
        $account = $this->credit($project);
        $user = User::factory()->create();
        $scope->members()->create(['user_id' => $user->id, 'role' => 'member', 'project_access_mode' => 'all', 'permissions' => ['allow' => ['ledger.view', 'ledger.write']]]);

        $this->actingAs($user)->patchJson("$url/accounts/{$account->id}", ['revision' => 1, 'opening_balance' => '0.00'])->assertForbidden();

        $this->assertDatabaseHas('ledger_accounts', ['id' => $account->id, 'opening_minor' => -10000, 'revision' => 1]);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_nonzero_bank_opening_cannot_be_converted_to_credit(): void
    {
        [, $project, , $url] = $this->workspace();
        $account = LedgerAccount::factory()->create(['project_id' => $project->id, 'opening_minor' => '10000']);
        $this->patchJson("$url/accounts/{$account->id}", ['revision' => 1, 'type' => 'credit', 'interest_rate_bps' => 100, 'interest_start_on' => '2026-01-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('opening_balance');
        $this->assertDatabaseHas('ledger_accounts', ['id' => $account->id, 'type' => 'bank', 'opening_minor' => 10000]);
    }

    public function test_statement_applies_payments_before_interest_and_ignores_plans_future_void_and_deleted(): void
    {
        [, $project, , $url] = $this->workspace();
        $account = $this->credit($project);
        LedgerTransaction::factory()->create(['account_id' => $account->id, 'kind' => 'income', 'amount_minor' => '5100', 'occurred_on' => '2026-01-02', 'status' => 'posted']);
        LedgerTransaction::factory()->create(['account_id' => $account->id, 'kind' => 'income', 'amount_minor' => '10000', 'occurred_on' => '2026-01-03', 'status' => 'posted']);
        LedgerTransaction::factory()->create(['account_id' => $account->id, 'amount_minor' => '100000', 'occurred_on' => '2026-01-04', 'status' => 'posted']);
        LedgerTransaction::factory()->create(['account_id' => $account->id, 'amount_minor' => '100000', 'occurred_on' => '2026-01-01', 'status' => 'planned']);
        LedgerTransaction::factory()->create(['account_id' => $account->id, 'amount_minor' => '100000', 'occurred_on' => '2026-01-01', 'status' => 'void']);
        LedgerTransaction::factory()->create(['account_id' => $account->id, 'amount_minor' => '100000', 'occurred_on' => '2026-01-01', 'status' => 'posted', 'deleted_at' => now()]);

        $this->getJson("$url/accounts/{$account->id}/statement?month=2026-01")->assertOk()->assertJsonCount(3, 'data.days')
            ->assertJsonPath('data.opening_minor', '-10000')->assertJsonPath('data.movement_minor', '15100')->assertJsonPath('data.interest_minor', '-150')
            ->assertJsonPath('data.closing_minor', '4950')->assertJsonPath('data.days.1.interest_minor', '-50')->assertJsonPath('data.days.2.interest_minor', '0');
        $this->getJson("$url/options")->assertJsonPath('data.accounts.0.balance_today_minor', '4950')->assertJsonPath('data.accounts.0.principal_today_minor', '5100');
        $this->assertDatabaseCount('ledger_transactions', 6);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_monthly_statement_carries_previous_interest_without_posting_duplicate_expenses(): void
    {
        [, $project, , $url] = $this->workspace();
        $account = $this->credit($project, ['interest_start_on' => '2026-01-31']);
        $this->travelTo(CarbonImmutable::parse('2026-02-02 12:00:00'));

        $this->getJson("$url/accounts/{$account->id}/statement?month=2026-02")->assertOk()->assertJsonPath('data.opening_minor', '-10100')
            ->assertJsonPath('data.interest_minor', '-203')->assertJsonPath('data.closing_minor', '-10303');
        $this->getJson("$url/accounts/{$account->id}/statement?month=2026-01")->assertOk()->assertJsonPath('data.closing_minor', '-10100');
        $this->assertDatabaseCount('ledger_transactions', 0);
    }

    public function test_interest_end_is_inclusive_and_archiving_does_not_silently_stop_accrual(): void
    {
        [, $project, , $url] = $this->workspace();
        $this->credit($project, ['interest_end_on' => '2026-01-02', 'is_archived' => true]);

        $this->getJson("$url/accounts?archived=1")->assertOk()->assertJsonPath('data.0.interest_minor', '-201')->assertJsonPath('data.0.balance_today_minor', '-10201');
    }

    public function test_transfers_affect_interest_on_both_sides_and_non_credit_accounts_have_no_interest(): void
    {
        [, $project, , $url] = $this->workspace();
        $credit = $this->credit($project);
        $cash = LedgerAccount::factory()->create(['project_id' => $project->id, 'opening_minor' => '20000']);
        LedgerTransaction::factory()->create(['account_id' => $cash->id, 'target_account_id' => $credit->id, 'kind' => 'transfer', 'amount_minor' => '10000', 'occurred_on' => '2026-01-01', 'status' => 'posted']);
        LedgerTransaction::factory()->create(['account_id' => $cash->id, 'target_account_id' => $credit->id, 'kind' => 'transfer', 'amount_minor' => '90000', 'occurred_on' => '2026-01-01', 'status' => 'posted', 'deleted_at' => now()]);

        $rows = collect($this->getJson("$url/accounts")->assertOk()->json('data'))->keyBy('id');

        $this->assertSame('0', $rows[$credit->id]['balance_today_minor']);
        $this->assertSame('0', $rows[$credit->id]['interest_minor']);
        $this->assertSame('10000', $rows[$cash->id]['balance_today_minor']);
        $this->assertSame('0', $rows[$cash->id]['interest_minor']);
    }

    public static function dailyCases(): array
    {
        return ['normal year' => [-365000, 10000, 365, -1000], 'leap year' => [-365000, 10000, 366, -997],
            'exact negative half' => [-1825000, 1, 365, -1], 'below half' => [-1824999, 1, 365, 0],
            'positive balance' => [10000, 36500, 365, 0], 'zero debt' => [0, 36500, 365, 0], 'zero rate' => [-10000, 0, 365, 0],
            'large exact integer' => [-9000000000000000, 100000, 365, -246575342465753]];
    }

    #[DataProvider('dailyCases')]
    public function test_daily_rounding_uses_exact_integers(int $balance, int $bps, int $year, int $expected): void
    {
        $this->assertSame($expected, (new LedgerInterestService)->dailyInterest($balance, $bps, $year));
    }

    public function test_zero_rate_and_future_start_do_not_accrue_interest(): void
    {
        [, $project, , $url] = $this->workspace();
        $zero = $this->credit($project, ['interest_rate_bps' => 0]);
        $future = $this->credit($project, ['interest_start_on' => '2026-02-01']);

        $rows = collect($this->getJson("$url/accounts")->assertOk()->json('data'))->keyBy('id');

        $this->assertSame('-10000', $rows[$zero->id]['balance_today_minor']);
        $this->assertSame('-10000', $rows[$future->id]['balance_today_minor']);
    }

    public static function invalidTerms(): array
    {
        return ['missing terms' => [[], 'interest_rate_bps'], 'negative rate' => [['interest_rate_bps' => -1, 'interest_start_on' => '2026-01-01'], 'interest_rate_bps'],
            'fractional basis points' => [['interest_rate_bps' => 1.5, 'interest_start_on' => '2026-01-01'], 'interest_rate_bps'],
            'excessive rate' => [['interest_rate_bps' => 100001, 'interest_start_on' => '2026-01-01'], 'interest_rate_bps'],
            'invalid date' => [['interest_rate_bps' => 100, 'interest_start_on' => '2026-02-30'], 'interest_start_on'],
            'end before start' => [['interest_rate_bps' => 100, 'interest_start_on' => '2026-01-02', 'interest_end_on' => '2026-01-01'], 'interest_end_on'],
            'terms on cash' => [['type' => 'cash', 'interest_rate_bps' => 100, 'interest_start_on' => '2026-01-01'], 'interest_rate_bps']];
    }

    #[DataProvider('invalidTerms')]
    public function test_invalid_terms_return_422_without_creating_account(array $terms, string $field): void
    {
        [, $project, , $url] = $this->workspace();

        $this->postJson("$url/accounts", [...['project_id' => $project->id, 'title' => 'Credit', 'currency' => 'RUB', 'type' => 'credit'], ...$terms])
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('ledger_accounts', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_terms_are_locked_even_after_the_only_transaction_is_deleted_and_title_remains_editable(): void
    {
        [, $project, , $url] = $this->workspace();
        $account = $this->credit($project);
        LedgerTransaction::factory()->create(['account_id' => $account->id, 'deleted_at' => now()]);

        $this->patchJson("$url/accounts/{$account->id}", ['revision' => 1, 'interest_rate_bps' => 1000])->assertUnprocessable()->assertJsonValidationErrors('interest_rate_bps');
        $this->patchJson("$url/accounts/{$account->id}", ['revision' => 1, 'interest_start_on' => '2026-01-02'])->assertUnprocessable()->assertJsonValidationErrors('interest_start_on');
        $this->patchJson("$url/accounts/{$account->id}", ['revision' => 1, 'title' => 'Новое имя'])->assertOk()->assertJsonPath('data.title', 'Новое имя')->assertJsonPath('data.revision', 2);
        $this->assertDatabaseHas('ledger_accounts', ['id' => $account->id, 'interest_rate_bps' => 36500, 'interest_start_on' => '2026-01-01']);
    }

    public function test_incoming_transfer_locks_terms_and_stale_revision_does_not_change_them(): void
    {
        [, $project, , $url] = $this->workspace();
        $account = $this->credit($project);
        $source = LedgerAccount::factory()->create(['project_id' => $project->id]);
        LedgerTransaction::factory()->create(['account_id' => $source->id, 'target_account_id' => $account->id, 'kind' => 'transfer']);

        $this->patchJson("$url/accounts/{$account->id}", ['revision' => 1, 'interest_end_on' => '2026-01-02'])->assertUnprocessable()->assertJsonValidationErrors('interest_end_on');
        $this->patchJson("$url/accounts/{$account->id}", ['revision' => 2, 'interest_rate_bps' => 1000])->assertConflict();
        $this->assertDatabaseHas('ledger_accounts', ['id' => $account->id, 'revision' => 1, 'interest_end_on' => null]);
    }

    public function test_statement_refuses_foreign_project_and_scope_without_disclosing_financial_data(): void
    {
        [$scope, $project, , $url] = $this->workspace();
        $account = $this->credit($project);
        $outsider = User::factory()->create();
        $scope->members()->create(['user_id' => $outsider->id, 'role' => 'member', 'project_access_mode' => 'none', 'permissions' => ['allow' => ['ledger.view']]]);
        $this->actingAs($outsider)->getJson("$url/accounts/{$account->id}/statement?month=2026-01")->assertNotFound();
        $other = LedgerAccount::factory()->create();
        $this->getJson("$url/accounts/{$other->id}/statement?month=2026-01")->assertNotFound();
    }

    public function test_statement_requires_financial_permission_and_a_valid_non_future_month(): void
    {
        [$scope, $project, , $url] = $this->workspace();
        $account = $this->credit($project);
        $this->getJson("$url/accounts/{$account->id}/statement?month=2026-02")->assertUnprocessable()->assertJsonValidationErrors('month');
        $this->getJson("$url/accounts/{$account->id}/statement?month=2026-13")->assertUnprocessable()->assertJsonValidationErrors('month');
        $outsider = User::factory()->create();
        $scope->members()->create(['user_id' => $outsider->id, 'role' => 'viewer', 'project_access_mode' => 'all']);
        $this->actingAs($outsider)->getJson("$url/accounts/{$account->id}/statement?month=2026-01")->assertForbidden();
    }

    public function test_interest_recalculates_after_payment_edit_delete_and_restore_without_stored_accruals(): void
    {
        [, $project, , $url] = $this->workspace();
        $account = $this->credit($project);
        $payment = $this->postJson("$url/transactions", ['account_id' => $account->id, 'kind' => 'income', 'title' => 'Погашение', 'amount' => '100.00',
            'occurred_on' => '2026-01-01', 'status' => 'posted', 'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $this->getJson("$url/accounts/{$account->id}/statement?month=2026-01")->assertJsonPath('data.interest_minor', '0');

        $this->deleteJson("$url/transactions/$payment", ['revision' => 1])->assertOk();
        $this->getJson("$url/accounts/{$account->id}/statement?month=2026-01")->assertJsonPath('data.interest_minor', '-303');
        $this->postJson("$url/transactions/$payment/restore", ['revision' => 2])->assertOk();
        $this->patchJson("$url/transactions/$payment", ['revision' => 3, 'occurred_on' => '2026-01-02'])->assertOk();
        $this->getJson("$url/accounts/{$account->id}/statement?month=2026-01")->assertJsonPath('data.interest_minor', '-102');
        $this->assertDatabaseCount('ledger_transactions', 1);
        $this->assertSame(4, ActivityLog::where('subject_type', 'ledger_transaction')->count());
    }

    public function test_agent_statement_requires_financial_token_ability_and_does_not_grant_write(): void
    {
        [$scope, $project] = $this->workspace();
        $account = $this->credit($project);
        $agent = User::factory()->create(['type' => 'agent']);
        $scope->members()->create(['user_id' => $agent->id, 'role' => 'admin', 'project_access_mode' => 'all']);
        $url = "/api/agent/scopes/{$scope->id}/ledger";
        Sanctum::actingAs($agent, ['task.view']);
        $this->getJson("$url/accounts/{$account->id}/statement?month=2026-01")->assertForbidden();
        Sanctum::actingAs($agent, ['ledger.view']);

        $this->getJson("$url/accounts/{$account->id}/statement?month=2026-01")->assertOk()->assertJsonPath('data.interest_minor', '-303');
        $this->patchJson("$url/accounts/{$account->id}", ['revision' => 1, 'interest_rate_bps' => 1000])->assertForbidden();
        $this->get('/api/agent/spec')->assertOk()->assertSee('interest_rate_bps', false)->assertSee('balance_today_minor', false)->assertSee('/statement?month=YYYY-MM', false);
        $this->assertDatabaseHas('ledger_accounts', ['id' => $account->id, 'interest_rate_bps' => 36500]);
    }

    public function test_unauthenticated_statement_returns_401(): void
    {
        $account = LedgerAccount::factory()->create();

        $this->withHeaders(['X-App-Request' => 'Zuratax'])->getJson("/api/scopes/{$account->scope_id}/ledger/accounts/{$account->id}/statement?month=2026-01")->assertUnauthorized();
    }

    public function test_interest_calculation_refuses_an_unsafe_balance_instead_of_using_floats(): void
    {
        $this->expectException(ValidationException::class);

        (new LedgerInterestService)->dailyInterest(-9000000000000001, 10000, 365);
    }

    public function test_unsafe_grouped_balance_returns_422_in_the_api_without_writing_accruals(): void
    {
        [, $project, , $url] = $this->workspace();
        $account = $this->credit($project);
        LedgerTransaction::factory()->count(2)->create(['account_id' => $account->id, 'amount_minor' => '4500000000000001', 'occurred_on' => '2026-01-01', 'status' => 'posted']);

        $this->getJson("$url/accounts/{$account->id}/statement?month=2026-01")->assertUnprocessable()->assertJsonValidationErrors('account_id');

        $this->assertDatabaseCount('ledger_transactions', 2);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_nonzero_opening_is_rejected_before_unsafe_historical_interest_is_calculated(): void
    {
        [, $project, , $url] = $this->workspace();

        $this->postJson("$url/accounts", ['project_id' => $project->id, 'title' => 'Unsafe', 'type' => 'credit', 'currency' => 'RUB',
            'opening_balance' => '-100.00', 'interest_rate_bps' => 100000, 'interest_start_on' => '1900-01-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('opening_balance');

        $this->assertDatabaseCount('ledger_accounts', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_payment_write_rolls_back_when_it_would_poison_the_credit_calculation(): void
    {
        [, $project, , $url] = $this->workspace();
        $account = $this->credit($project, ['opening_minor' => '0', 'interest_rate_bps' => 100000, 'interest_start_on' => '1900-01-01']);

        $this->postJson("$url/transactions", ['account_id' => $account->id, 'kind' => 'expense', 'title' => 'Unsafe expense', 'amount' => '100.00',
            'occurred_on' => '1900-01-01', 'status' => 'posted', 'idempotency_key' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors('account_id');

        $this->assertDatabaseCount('ledger_transactions', 0);
        $this->assertDatabaseCount('activity_logs', 0);
        $this->assertDatabaseHas('ledger_accounts', ['id' => $account->id, 'revision' => 1]);
    }

    public function test_account_terms_update_rolls_back_when_the_new_projection_is_unsafe(): void
    {
        [, $project, , $url] = $this->workspace();
        $account = $this->credit($project, ['interest_rate_bps' => 0, 'interest_start_on' => '1900-01-01']);

        $this->patchJson("$url/accounts/{$account->id}", ['revision' => 1, 'interest_rate_bps' => 100000])->assertUnprocessable();

        $this->assertDatabaseHas('ledger_accounts', ['id' => $account->id, 'interest_rate_bps' => 0, 'revision' => 1]);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_posting_an_old_plan_rolls_back_when_the_result_is_unsafe(): void
    {
        [, $project, , $url] = $this->workspace();
        $account = $this->credit($project, ['opening_minor' => '0', 'interest_rate_bps' => 100000, 'interest_start_on' => '1900-01-01']);
        $payment = LedgerTransaction::factory()->create(['account_id' => $account->id, 'occurred_on' => '1900-01-01', 'amount_minor' => '10000']);

        $this->patchJson("$url/transactions/{$payment->id}", ['revision' => 1, 'status' => 'posted'])->assertUnprocessable();

        $this->assertDatabaseHas('ledger_transactions', ['id' => $payment->id, 'status' => 'planned', 'revision' => 1]);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_removing_a_repayment_rolls_back_the_deletion_when_the_result_is_unsafe(): void
    {
        [, $project, , $url] = $this->workspace();
        $account = $this->credit($project, ['opening_minor' => '0', 'interest_rate_bps' => 100000, 'interest_start_on' => '1900-01-01']);
        LedgerTransaction::factory()->create(['account_id' => $account->id, 'occurred_on' => '1900-01-01', 'amount_minor' => '10000', 'status' => 'posted']);
        $payment = LedgerTransaction::factory()->create(['account_id' => $account->id, 'occurred_on' => '1900-01-01', 'amount_minor' => '10000', 'status' => 'posted', 'kind' => 'income']);

        $this->deleteJson("$url/transactions/{$payment->id}", ['revision' => 1])->assertUnprocessable();

        $this->assertDatabaseHas('ledger_transactions', ['id' => $payment->id, 'deleted_at' => null, 'revision' => 1]);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_restoring_an_expense_rolls_back_the_restoration_when_the_result_is_unsafe(): void
    {
        [, $project, , $url] = $this->workspace();
        $account = $this->credit($project, ['opening_minor' => '0', 'interest_rate_bps' => 100000, 'interest_start_on' => '1900-01-01']);
        $payment = LedgerTransaction::factory()->create(['account_id' => $account->id, 'occurred_on' => '1900-01-01', 'amount_minor' => '10000', 'status' => 'posted', 'deleted_at' => now()]);

        $this->postJson("$url/transactions/{$payment->id}/restore", ['revision' => 1])->assertUnprocessable();

        $this->assertSoftDeleted($payment);
        $this->assertDatabaseHas('ledger_transactions', ['id' => $payment->id, 'revision' => 1]);
        $this->assertDatabaseCount('activity_logs', 0);
    }
}
