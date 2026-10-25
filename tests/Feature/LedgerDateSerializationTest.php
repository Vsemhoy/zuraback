<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\Project;
use App\Models\Scope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LedgerDateSerializationTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): array
    {
        $owner = User::factory()->create();
        $scope = Scope::factory()->create(['owner_id' => $owner->id]);
        $project = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $account = LedgerAccount::factory()->create(['project_id' => $project->id]);
        $this->actingAs($owner)->withHeaders(['X-App-Request' => 'Zuratax']);

        return [$scope, $owner, $account];
    }

    public static function calendarDates(): array
    {
        return ['UTC month end' => ['2026-10-31', 'UTC'], 'Moscow year boundary' => ['2026-01-01', 'Europe/Moscow'],
            'leap day east of UTC' => ['2024-02-29', 'Pacific/Kiritimati'], 'west of UTC' => ['2026-03-08', 'America/Los_Angeles']];
    }

    #[DataProvider('calendarDates')]
    public function test_journal_and_detail_return_date_only_without_timezone_shift(string $day, string $timezone): void
    {
        [$scope, , $account] = $this->workspace();
        $transaction = LedgerTransaction::factory()->create(['account_id' => $account->id, 'occurred_on' => $day]);
        $url = "/api/scopes/{$scope->id}/ledger/transactions";
        $previous = date_default_timezone_get();
        try {
            date_default_timezone_set($timezone);

            $this->getJson($url)->assertOk()->assertJsonPath('data.0.occurred_on', $day);
            $this->getJson("$url/{$transaction->id}")->assertOk()->assertJsonPath('data.occurred_on', $day);
        } finally {
            date_default_timezone_set($previous);
        }

        $this->assertDatabaseHas('ledger_transactions', ['id' => $transaction->id, 'occurred_on' => $day]);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public static function clients(): array
    {
        return ['browser' => [false], 'agent' => [true]];
    }

    #[DataProvider('clients')]
    public function test_write_responses_keep_calendar_date_for_browser_and_agent(bool $agent): void
    {
        [$scope, , $account] = $this->workspace();
        if ($agent) {
            $actor = User::factory()->agent()->create();
            $scope->members()->create(['user_id' => $actor->id, 'role' => 'admin', 'project_access_mode' => 'all']);
            Sanctum::actingAs($actor, ['ledger.view', 'ledger.write', 'ledger.manage']);
        }
        $url = '/api/'.($agent ? 'agent/' : '')."scopes/{$scope->id}/ledger/transactions";

        $id = $this->postJson($url, ['account_id' => $account->id, 'kind' => 'expense', 'amount' => '1.25', 'occurred_on' => '2026-10-31',
            'title' => 'Проверка даты', 'status' => 'posted', 'idempotency_key' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('data.occurred_on', '2026-10-31')->json('data.id');
        $this->patchJson("$url/$id", ['revision' => 1, 'title' => 'Дата сохранена'])->assertOk()->assertJsonPath('data.occurred_on', '2026-10-31');
        $this->patchJson("$url/$id", ['revision' => 2, 'occurred_on' => '2026-11-01'])->assertOk()->assertJsonPath('data.occurred_on', '2026-11-01');
        $this->deleteJson("$url/$id", ['revision' => 3])->assertOk();
        $this->getJson("$url?trash=1")->assertOk()->assertJsonPath('data.0.occurred_on', '2026-11-01');
        $this->postJson("$url/$id/restore", ['revision' => 4])->assertOk()->assertJsonPath('data.occurred_on', '2026-11-01');
        $this->getJson("$url/$id")->assertOk()->assertJsonPath('data.occurred_on', '2026-11-01');

        $this->assertDatabaseHas('ledger_transactions', ['id' => $id, 'occurred_on' => '2026-11-01', 'amount_minor' => 125, 'revision' => 5, 'deleted_at' => null]);
        $this->assertDatabaseCount('ledger_transactions', 1);
        $this->assertSame(5, ActivityLog::where('subject_type', 'ledger_transaction')->where('subject_id', $id)->count());
    }
}
