<?php

namespace Tests\Feature\Api;

use App\Models\Scope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KpiApiTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-App-Request' => 'Zuratax'];

    public function test_kpis_are_scope_rows_manually_linked_to_tasks(): void
    {
        [$user, $scope] = $this->workspace();
        $kpi = $this->actingAs($user)->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/kpis", [
            'name' => 'Закрывать заявки', 'description' => 'Работы первой линии', 'kind' => 'bonus',
            'points' => 15, 'minimum_completed_tasks' => 2,
        ])->assertCreated()->assertJsonPath('data.kind', 'bonus')->json('data');

        $task = $this->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/tasks", [
            'title' => 'Закрыть заявку', 'assignee_id' => $user->id, 'kpi_id' => $kpi['id'],
        ])->assertCreated()->assertJsonPath('data.kpi.id', $kpi['id'])->json('data');
        $this->withHeaders(self::HEADERS)->patchJson("/api/scopes/{$scope->id}/tasks/{$task['id']}", ['status' => 'done'])->assertOk();

        $this->withHeaders(self::HEADERS)->getJson("/api/scopes/{$scope->id}/kpis")
            ->assertOk()->assertJsonPath('data.0.tasks_count', 1);
        $this->assertDatabaseHas('tasks', ['id' => $task['id'], 'kpi_id' => $kpi['id']]);
    }

    public function test_scope_targets_and_monthly_people_stats_are_calculated_without_result_tables(): void
    {
        [$user, $scope] = $this->workspace();
        $kpi = $this->actingAs($user)->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/kpis", [
            'name' => 'Премиальный KPI', 'kind' => 'bonus', 'points' => 55, 'minimum_completed_tasks' => 1,
        ])->assertCreated()->json('data');
        $this->withHeaders(self::HEADERS)->putJson("/api/scopes/{$scope->id}/kpis/settings", [
            'salary_target_points' => 100, 'bonus_target_points' => 75, 'bonus_cap_percent' => 50,
        ])->assertOk()->assertJsonPath('data.bonus_cap_percent', 50);
        $task = $this->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/tasks", [
            'title' => 'Выполнить KPI', 'assignee_id' => $user->id, 'kpi_id' => $kpi['id'],
        ])->assertCreated()->json('data');
        $this->withHeaders(self::HEADERS)->patchJson("/api/scopes/{$scope->id}/tasks/{$task['id']}", ['status' => 'done'])->assertOk();

        $this->withHeaders(self::HEADERS)->getJson("/api/scopes/{$scope->id}/kpis/stats?month=".now()->format('Y-m'))
            ->assertOk()
            ->assertJsonPath('data.people.0.user.id', $user->id)
            ->assertJsonPath('data.people.0.bonus_points', 55)
            ->assertJsonPath('data.people.0.payable_bonus_percent', 50)
            ->assertJsonPath('data.people.0.areas.0.qualified', true);
    }

    private function workspace(): array
    {
        $user = User::factory()->create();
        $scope = Scope::query()->create(['owner_id' => $user->id, 'name' => 'Work', 'slug' => 'work']);
        $scope->members()->create(['user_id' => $user->id, 'role' => 'owner', 'joined_at' => now()]);

        return [$user, $scope];
    }
}
