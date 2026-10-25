<?php

namespace Tests\Feature;

use App\Models\LedgerAccount;
use App\Models\Project;
use App\Models\Scope;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LedgerMonthBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public static function boundaries(): array
    {
        return [
            '31 March after February' => ['2026-03-31', '2026-02', '2026-02-28', 28, 36500],
            '31 March after leap February' => ['2024-03-31', '2024-02', '2024-02-29', 29, 36600],
            '31 May after April' => ['2026-05-31', '2026-04', '2026-04-30', 30, 36500],
            'January after prior year' => ['2027-01-31', '2026-12', '2026-12-31', 31, 36500],
        ];
    }

    #[DataProvider('boundaries')]
    public function test_previous_month_closes_and_next_month_opens_without_day_overflow(string $today, string $previousMonth, string $lastDay, int $days, int $rate): void
    {
        $this->travelTo(CarbonImmutable::parse($today.' 12:00:00'));
        $owner = User::factory()->create();
        $scope = Scope::factory()->create(['owner_id' => $owner->id]);
        $project = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $account = LedgerAccount::factory()->create(['project_id' => $project->id, 'type' => 'credit', 'opening_minor' => '-10000',
            'interest_rate_bps' => $rate, 'interest_start_on' => $lastDay]);
        $this->actingAs($owner)->withHeaders(['X-App-Request' => 'Zuratax', 'Accept' => 'application/json']);
        $url = "/api/scopes/{$scope->id}/ledger/accounts/{$account->id}/statement";

        $this->getJson($url.'?month='.$previousMonth)->assertOk()->assertJsonPath('data.month', $previousMonth)
            ->assertJsonPath('data.as_of', $lastDay)->assertJsonCount($days, 'data.days')->assertJsonPath('data.days.0.date', $previousMonth.'-01')
            ->assertJsonPath('data.days.'.($days - 1).'.date', $lastDay)->assertJsonPath('data.interest_minor', '-100')->assertJsonPath('data.closing_minor', '-10100');
        $month = substr($today, 0, 7);
        $this->getJson($url.'?month='.$month)->assertOk()->assertJsonPath('data.opening_minor', '-10100')
            ->assertJsonPath('data.days.0.date', $month.'-01')->assertJsonPath('data.days.0.interest_minor', '-101')
            ->assertJsonPath('data.days.0.closing_minor', '-10201');

        $this->assertDatabaseCount('ledger_transactions', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }
}
