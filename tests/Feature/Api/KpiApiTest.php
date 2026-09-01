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
            ->assertJsonPath('data.people.0.areas.0.qualified', true)
            ->assertJsonPath('data.people.0.areas.0.tasks.0.task_key', $task['task_key'])
            ->assertJsonPath('data.people.0.areas.0.tasks.0.title', 'Выполнить KPI');
    }

    public function test_monthly_report_filters_a_person_and_hides_kpis_without_completed_tasks(): void
    {
        [$owner, $scope] = $this->workspace();
        $colleague = User::factory()->create();
        $scope->members()->create(['user_id' => $colleague->id, 'role' => 'member', 'joined_at' => now()]);
        $doneKpi = $scope->kpis()->create(['created_by' => $owner->id, 'name' => 'Сделано', 'kind' => 'bonus', 'points' => 20, 'minimum_completed_tasks' => 1]);
        $scope->kpis()->create(['created_by' => $owner->id, 'name' => 'Не сделано', 'kind' => 'bonus', 'points' => 30, 'minimum_completed_tasks' => 1]);
        $task = $scope->tasks()->create(['created_by' => $owner->id, 'assignee_id' => $colleague->id, 'kpi_id' => $doneKpi->id, 'title' => 'Готовая работа', 'status' => 'done', 'completed_at' => now()]);

        $this->actingAs($owner)->withHeaders(self::HEADERS)->getJson("/api/scopes/{$scope->id}/kpis/stats?month=".now()->format('Y-m')."&user_id={$colleague->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.people')
            ->assertJsonPath('data.people.0.user.id', $colleague->id)
            ->assertJsonCount(1, 'data.people.0.areas')
            ->assertJsonPath('data.people.0.areas.0.id', $doneKpi->id)
            ->assertJsonPath('data.people.0.areas.0.tasks.0.id', $task->id);
    }

    private function workspace(): array
    {
        $user = User::factory()->create();
        $scope = Scope::query()->create(['owner_id' => $user->id, 'name' => 'Work', 'slug' => 'work']);
        $scope->members()->create(['user_id' => $user->id, 'role' => 'owner', 'joined_at' => now()]);

        return [$user, $scope];
    }
}
