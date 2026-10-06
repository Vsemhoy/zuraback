<?php

namespace Tests\Feature\Api;

use App\Models\MonthlyTaskPlan;
use App\Models\PlanItem;
use App\Models\Scope;
use App\Models\Task;
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
        $task = $scope->tasks()->create(['created_by' => $owner->id, 'assignee_id' => $colleague->id, 'kpi_id' => $doneKpi->id, 'title' => 'Готовая работа', 'status' => 'done', 'due_at' => now(), 'completed_at' => now()]);

        $this->actingAs($owner)->withHeaders(self::HEADERS)->getJson("/api/scopes/{$scope->id}/kpis/stats?month=".now()->format('Y-m')."&user_id={$colleague->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.people')
            ->assertJsonPath('data.people.0.user.id', $colleague->id)
            ->assertJsonCount(1, 'data.people.0.areas')
            ->assertJsonPath('data.people.0.areas.0.id', $doneKpi->id)
            ->assertJsonPath('data.people.0.areas.0.tasks.0.id', $task->id);
    }

    public function test_completion_filter_uses_only_calendar_date_and_keeps_points_independent_of_display(): void
    {
        [$user, $scope] = $this->workspace();
        $kpi = $scope->kpis()->create(['created_by' => $user->id, 'name' => 'KPI', 'kind' => 'bonus', 'points' => 20, 'minimum_completed_tasks' => 1]);
        $completedOnlyKpi = $scope->kpis()->create(['created_by' => $user->id, 'name' => 'Other KPI', 'kind' => 'bonus', 'points' => 10, 'minimum_completed_tasks' => 1]);
        $attributes = ['scope_id' => $scope->id, 'created_by' => $user->id, 'assignee_id' => $user->id, 'kpi_id' => $kpi->id];
        $done = Task::factory()->create([...$attributes, 'status' => 'done', 'due_at' => '2026-10-02 12:00:00', 'completed_at' => '2026-11-02 12:00:00']);
        $otherDone = Task::factory()->create([...$attributes, 'kpi_id' => $completedOnlyKpi->id, 'status' => 'done', 'due_at' => '2026-10-03 12:00:00', 'completed_at' => '2026-10-03 12:00:00']);
        $monthly = Task::factory()->create($attributes);
        MonthlyTaskPlan::query()->create(['scope_id' => $scope->id, 'task_id' => $monthly->id, 'assignee_id' => $user->id, 'created_by' => $user->id, 'month' => '2026-10']);
        $monthly->plannerTails()->create(['scope_id' => $scope->id, 'created_by' => $user->id, 'planned_on' => '2026-10-20']);
        Task::factory()->create([...$attributes, 'status' => 'done', 'completed_at' => '2026-10-03']);
        $annual = Task::factory()->create($attributes);
        $plan = PlanItem::factory()->create(['scope_id' => $scope->id, 'created_by' => $user->id, 'month' => '2026-10']);
        $plan->tasks()->attach($annual);
        $due = Task::factory()->create([...$attributes, 'due_at' => '2026-10-31 23:59:59']);
        $plan->tasks()->attach($due);
        Task::factory()->create([...$attributes, 'due_at' => '2026-11-01 00:00:00']);
        Task::factory()->create([...$attributes, 'status' => 'done', 'completed_at' => '2026-10-05', 'due_at' => '2026-09-30 23:59:59']);
        Task::factory()->create([...$attributes, 'status' => 'cancelled', 'due_at' => '2026-10-05']);
        Task::factory()->create($attributes);
        Task::factory()->create([...$attributes, 'assignee_id' => User::factory()->create()->id, 'due_at' => '2026-10-05']);
        Task::factory()->create([...$attributes, 'due_at' => '2026-10-05'])->delete();
        $nextPlanTask = Task::factory()->create($attributes);
        MonthlyTaskPlan::query()->create(['scope_id' => $scope->id, 'task_id' => $nextPlanTask->id, 'created_by' => $user->id, 'month' => '2026-11']);
        $url = "/api/scopes/{$scope->id}/kpis/stats?month=2026-10&user_id={$user->id}";
        $this->actingAs($user)->withHeaders(self::HEADERS);

        foreach (['completed' => [$done->id, $otherDone->id], 'incomplete' => [$due->id], 'all' => [$done->id, $otherDone->id, $due->id]] as $filter => $expected) {
            $response = $this->getJson($url.'&completion='.$filter)->assertOk()->assertJsonPath('data.people.0.bonus_points', 30);
            $rows = collect($response->json('data.people.0.areas'));
            $this->assertEqualsCanonicalizing($expected, $rows->pluck('tasks')->flatten(1)->pluck('id')->all());
            $this->assertSame(1, $rows->firstWhere('id', $kpi->id)['completed_tasks']);
            $this->assertTrue($rows->firstWhere('id', $kpi->id)['qualified']);
            if ($filter === 'incomplete') {
                $this->assertCount(1, $rows);
                $this->assertSame('todo', $rows->first()['tasks'][0]['status']);
            }
        }
        $default = $this->getJson($url)->assertOk()->json('data.people.0.areas');
        $this->assertEqualsCanonicalizing([$done->id, $otherDone->id], collect($default)->pluck('tasks')->flatten(1)->pluck('id')->all());
    }

    public function test_incomplete_tasks_do_not_award_kpi_points_and_filters_are_validated(): void
    {
        [$user, $scope] = $this->workspace();
        $kpi = $scope->kpis()->create(['created_by' => $user->id, 'name' => 'Pending KPI', 'kind' => 'salary', 'points' => 40, 'minimum_completed_tasks' => 1]);
        Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $user->id, 'assignee_id' => $user->id, 'kpi_id' => $kpi->id, 'due_at' => '2026-10-10']);
        $url = "/api/scopes/{$scope->id}/kpis/stats";
        $this->actingAs($user)->withHeaders(self::HEADERS)
            ->getJson($url.'?month=2026-10&completion=incomplete')->assertOk()
            ->assertJsonPath('data.people.0.salary_points', 0)
            ->assertJsonPath('data.people.0.areas.0.qualified', false)
            ->assertJsonPath('data.people.0.areas.0.completed_tasks', 0)
            ->assertJsonCount(1, 'data.people.0.areas.0.tasks');
        $this->getJson($url.'?completion=unknown')->assertUnprocessable()->assertJsonValidationErrors('completion');
        $this->getJson($url.'?month=2026-13')->assertUnprocessable()->assertJsonValidationErrors('month');
    }

    public function test_completing_tasks_schedules_today_and_preserves_existing_calendar_dates(): void
    {
        [$user, $scope] = $this->workspace();
        $this->travelTo(now()->setDate(2026, 10, 7)->setTime(9, 30));
        $base = "/api/scopes/{$scope->id}";
        $this->actingAs($user)->withHeaders(self::HEADERS);
        $created = $this->postJson($base.'/tasks', ['title' => 'Already done', 'status' => 'done'])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('tasks', ['id' => $created, 'due_at' => '2026-10-07 12:00:00']);
        $task = Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $user->id]);
        $this->patchJson($base.'/tasks/'.$task->id, ['status' => 'done'])->assertOk();
        $this->assertSame('2026-10-07', $task->fresh()->due_at->toDateString());
        $scheduled = Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $user->id, 'due_at' => '2026-09-15 10:00:00']);
        $this->patchJson($base.'/tasks/'.$scheduled->id, ['status' => 'done'])->assertOk();
        $this->assertDatabaseHas('tasks', ['id' => $scheduled->id, 'due_at' => '2026-09-15 10:00:00']);
        $bulk = Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $user->id]);
        $this->patchJson($base.'/planner/tasks/bulk', ['task_ids' => [$bulk->id], 'status' => 'done'])->assertOk();
        $this->assertSame('2026-10-07', $bulk->fresh()->due_at->toDateString());
    }

    private function workspace(): array
    {
        $user = User::factory()->create();
        $scope = Scope::query()->create(['owner_id' => $user->id, 'name' => 'Work', 'slug' => 'work']);
        $scope->members()->create(['user_id' => $user->id, 'role' => 'owner', 'joined_at' => now()]);

        return [$user, $scope];
    }
}
