<?php

namespace Tests\Feature\Api;

use App\Models\Kpi;
use App\Models\MonthlyReport;
use App\Models\PlanItem;
use App\Models\Project;
use App\Models\Scope;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class PlanItemTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-App-Request' => 'Zuratax'];

    public function test_candidates_and_selected_tasks_include_existing_plans_without_blocking_reuse(): void
    {
        [$user, $scope, $base] = $this->workspace();
        $task = Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $user->id, 'project_id' => null, 'assignee_id' => $user->id, 'status' => 'done', 'due_at' => '2026-11-10']);
        $first = PlanItem::factory()->create(['scope_id' => $scope->id, 'created_by' => $user->id, 'project_id' => null, 'title' => 'First plan', 'month' => '2026-10']);
        $first->tasks()->attach($task);
        $this->getJson($base.'/plans/candidates')->assertOk()->assertJsonCount(1, 'data.0.linked_plans')
            ->assertJsonPath('data.0.assignee.name', $user->name)->assertJsonPath('data.0.linked_plans.0.title', 'First plan')->assertJsonPath('data.0.linked_plans.0.month', '2026-10');
        $this->postJson($base.'/plans', ['title' => 'Second plan', 'month' => '2026-11', 'task_ids' => [$task->id], 'task_dates' => [$task->id => '2026-11-11']])
            ->assertUnprocessable()->assertJsonValidationErrors('due_at');
        $this->assertDatabaseCount('plan_items', 1);
        $this->assertSame('2026-11-10', $task->fresh()->due_at->toDateString());
        $second = $this->postJson($base.'/plans', ['title' => 'Second plan', 'month' => '2026-11', 'task_ids' => [$task->id], 'task_dates' => [$task->id => '2026-11-10']])
            ->assertOk()->assertJsonCount(2, 'data.tasks.0.linked_plans')->json('data.id');
        $this->getJson($base.'/plans/'.$first->id)->assertOk()->assertJsonCount(2, 'data.tasks.0.linked_plans');
        $this->getJson($base.'/plans?year=2026')->assertOk()->assertJsonCount(2, 'data.items.0.tasks.0.linked_plans')->assertJsonPath('data.items.0.tasks.0.assignee.name', $user->name);
        $this->deleteJson($base.'/plans/'.$second)->assertNoContent();
        $this->getJson($base.'/plans/candidates')->assertOk()->assertJsonCount(1, 'data.0.linked_plans');
        $this->patchJson($base.'/plans/'.$first->id, ['task_ids' => []])->assertOk();
        $this->getJson($base.'/plans/candidates')->assertOk()->assertJsonCount(0, 'data.0.linked_plans');
    }

    public function test_plan_hints_never_expose_foreign_or_stale_project_links(): void
    {
        [$owner, $scope, $base] = $this->workspace();
        $task = Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'project_id' => null]);
        $visible = PlanItem::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'project_id' => null]);
        $visible->tasks()->attach($task);
        $foreignScope = Scope::factory()->create(['owner_id' => $owner->id]);
        $foreign = PlanItem::factory()->create(['scope_id' => $foreignScope->id, 'created_by' => $owner->id, 'project_id' => null, 'title' => 'Foreign secret']);
        $foreign->tasks()->attach($task);
        $private = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'private']);
        $stale = PlanItem::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'project_id' => $private->id, 'title' => 'Private secret']);
        $stale->tasks()->attach($task);
        $this->getJson($base.'/plans/candidates')->assertOk()->assertJsonCount(1, 'data.0.linked_plans')
            ->assertJsonMissing(['title' => 'Foreign secret'])->assertJsonMissing(['title' => 'Private secret']);
        $member = User::factory()->create();
        $scope->members()->create(['user_id' => $member->id, 'role' => 'member', 'project_access_mode' => 'all']);
        $this->actingAs($member)->getJson($base.'/plans/candidates')->assertOk()->assertJsonCount(1, 'data.0.linked_plans')
            ->assertJsonPath('data.0.linked_plans.0.id', $visible->id);
    }

    public function test_task_links_require_calendar_dates_in_the_plan_month_and_save_atomically(): void
    {
        [$user, $scope, $base] = $this->workspace();
        $task = Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $user->id]);
        $payload = ['title' => 'Scheduled work', 'month' => '2026-10', 'task_ids' => [$task->id]];
        $this->postJson($base.'/plans', $payload)->assertUnprocessable()->assertJsonValidationErrors('task_dates.'.$task->id);
        $this->postJson($base.'/plans', [...$payload, 'task_dates' => [$task->id => '2026-11-01']])->assertUnprocessable();
        $this->assertDatabaseCount('plan_items', 0);
        $this->assertNull($task->fresh()->due_at);
        $id = $this->postJson($base.'/plans', [...$payload, 'task_dates' => [$task->id => '2026-10-31']])->assertOk()->json('data.id');
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'due_at' => '2026-10-31 12:00:00']);
        $this->patchJson($base.'/plans/'.$id, ['month' => '2026-11'])->assertUnprocessable();
        $this->assertDatabaseHas('plan_items', ['id' => $id, 'month' => '2026-10']);
        $this->patchJson($base.'/plans/'.$id, ['month' => '2026-11', 'task_dates' => [$task->id => '2026-11-01']])->assertOk();
        $this->assertSame('2026-11-01', $task->fresh()->due_at->toDateString());
        $this->postJson($base.'/plans', [...$payload, 'month' => '2026-11'])->assertOk();
    }

    private function workspace(): array
    {
        $user = User::factory()->create(['is_executor' => true]);
        $scope = Scope::factory()->create(['owner_id' => $user->id]);
        $this->actingAs($user)->withHeaders(self::HEADERS);

        return [$user, $scope, "/api/scopes/{$scope->id}"];
    }

    public function test_plan_can_be_completed_without_tasks_and_audits_completion_and_reopening(): void
    {
        [$user, $scope, $base] = $this->workspace();
        $this->freezeTime();
        $id = $this->postJson($base.'/plans', ['title' => 'Улучшить резервирование', 'month' => '2026-10', 'expected_result' => 'Резервы без гонок', 'impact' => 'Меньше пересортицы', 'estimated_minutes' => 90])->assertOk()->assertJsonPath('data.tasks_count', 0)->json('data.id');
        $this->patchJson($base.'/plans/'.$id, ['completed' => true])->assertOk()->assertJsonPath('data.completed_by', $user->id)->assertJsonPath('data.tasks_count', 0);
        $at = PlanItem::findOrFail($id)->completed_at;
        $this->travel(1)->hours();
        $this->patchJson($base.'/plans/'.$id, ['completed' => true])->assertOk();
        $this->assertTrue($at->equalTo(PlanItem::findOrFail($id)->completed_at));
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $id, 'action' => 'plan.updated']);
        $this->patchJson($base.'/plans/'.$id, ['completed' => false])->assertOk()->assertJsonPath('data.completed_at', null)->assertJsonPath('data.completed_by', null);
    }

    public function test_task_progress_is_independent_and_calendar_is_unchanged(): void
    {
        [$user, $scope, $base] = $this->workspace();
        $project = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $user->id]);
        $done = Task::factory()->create(['scope_id' => $scope->id, 'project_id' => $project->id, 'created_by' => $user->id, 'status' => 'done', 'due_at' => '2026-10-01']);
        $todo = Task::factory()->create(['scope_id' => $scope->id, 'project_id' => $project->id, 'created_by' => $user->id, 'status' => 'todo', 'due_at' => '2026-10-05 10:00:00']);
        $id = $this->postJson($base.'/plans', ['title' => 'Этап', 'month' => '2026-10', 'project_id' => $project->id, 'task_ids' => [$done->id, $todo->id], 'completed' => true])
            ->assertOk()->assertJsonPath('data.tasks_count', 2)->assertJsonPath('data.completed_tasks_count', 1)->json('data.id');
        $this->assertDatabaseHas('tasks', ['id' => $todo->id, 'status' => 'todo', 'due_at' => '2026-10-05 10:00:00']);
        $this->deleteJson($base.'/plans/'.$id)->assertNoContent();
        $this->assertSoftDeleted('plan_items', ['id' => $id]);
        $this->assertDatabaseHas('tasks', ['id' => $todo->id]);
    }

    public function test_scope_and_project_boundaries_protect_plan_reads_writes_and_links(): void
    {
        [$owner, $scope, $base] = $this->workspace();
        $otherScope = Scope::factory()->create(['owner_id' => $owner->id]);
        $foreign = PlanItem::factory()->create(['scope_id' => $otherScope->id, 'created_by' => $owner->id]);
        $this->getJson($base.'/plans/'.$foreign->id)->assertNotFound();
        $this->patchJson($base.'/plans/'.$foreign->id, ['completed' => true])->assertNotFound();
        $this->deleteJson($base.'/plans/'.$foreign->id)->assertNotFound();
        $secret = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'private']);
        $plan = PlanItem::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'project_id' => $secret->id]);
        $member = User::factory()->create();
        $scope->members()->create(['user_id' => $member->id, 'role' => 'member', 'project_access_mode' => 'all']);
        $this->actingAs($member)->getJson($base.'/plans?year=2026')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson($base.'/plans/'.$plan->id)->assertNotFound();
        $this->postJson($base.'/plans', ['title' => 'Тест', 'month' => '2026-10', 'project_id' => $secret->id])->assertNotFound();
        $this->assertDatabaseCount('plan_items', 2);
    }

    public function test_validation_rejects_mismatched_links_and_invalid_partial_date_range(): void
    {
        [$user, $scope, $base] = $this->workspace();
        $task = Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $user->id, 'project_id' => Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $user->id])->id]);
        $this->postJson($base.'/plans', ['title' => 'Без проекта', 'month' => '2026-10', 'task_ids' => [$task->id]])->assertUnprocessable()->assertJsonPath('message', 'Связывайте доступные задачи того же проекта. Для смены проекта сначала уберите прежние связи.');
        $this->postJson($base.'/plans', ['month' => '2026-13'])->assertUnprocessable()->assertJsonValidationErrors(['title', 'month']);
        $id = $this->postJson($base.'/plans', ['title' => 'Тест', 'month' => '2026-10', 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-25'])->assertOk()->json('data.id');
        $this->patchJson($base.'/plans/'.$id, ['ends_on' => '2026-10-10'])->assertUnprocessable()->assertJsonValidationErrors('ends_on');
        $this->assertDatabaseHas('plan_items', ['id' => $id, 'ends_on' => '2026-10-25']);
    }

    public function test_observer_cannot_write_or_override_system_fields(): void
    {
        [$user, $scope, $base] = $this->workspace();
        $observer = User::factory()->create();
        $scope->members()->create(['user_id' => $observer->id, 'role' => 'observer', 'project_access_mode' => 'all']);
        $this->actingAs($observer)->postJson($base.'/plans', ['title' => 'Тест', 'month' => '2026-10'])->assertForbidden();
        $this->actingAs($user)->postJson($base.'/plans', ['title' => 'Тест', 'month' => '2026-10', 'created_by' => $observer->id, 'completed_by' => $observer->id, 'completed_at' => '2026-01-01'])
            ->assertOk()->assertJsonPath('data.created_by', $user->id)->assertJsonPath('data.completed_at', null);
    }

    public function test_excluded_projects_stay_in_planner_but_not_new_reports_or_kpis(): void
    {
        Storage::fake('reports');
        [$user, $scope, $base] = $this->workspace();
        $project = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $user->id]);
        $kpi = Kpi::factory()->create(['scope_id' => $scope->id, 'created_by' => $user->id, 'kind' => 'bonus', 'points' => 55, 'minimum_completed_tasks' => 1]);
        $task = Task::factory()->create(['scope_id' => $scope->id, 'project_id' => $project->id, 'created_by' => $user->id, 'assignee_id' => $user->id, 'status' => 'done', 'completed_at' => '2026-09-15 10:00:00', 'kpi_id' => $kpi->id]);
        $plan = PlanItem::factory()->create(['scope_id' => $scope->id, 'created_by' => $user->id, 'project_id' => $project->id, 'month' => '2026-09']);
        $plan->tasks()->attach($task->id);
        $filters = ['month' => '2026-09', 'timezone' => 'Europe/Moscow'];
        $archive = $this->postJson($base.'/reports/monthly/archives', $filters)->assertCreated()->json('data.id');
        $report = MonthlyReport::findOrFail($archive);
        $hash = hash_file('sha256', Storage::disk('reports')->path($report->file_path));
        $this->patchJson($base.'/projects/'.$project->id, ['include_in_reports' => false])->assertOk()->assertJsonPath('data.include_in_reports', false);
        $this->getJson($base.'/plans?year=2026')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson($base.'/reports/monthly?'.http_build_query($filters))->assertOk()->assertJsonCount(0, 'data.completed')->assertJsonCount(0, 'data.kpis')->assertJsonCount(0, 'data.plan_items');
        $this->getJson($base.'/kpis/stats?month=2026-09')->assertOk()->assertJsonPath('data.people.0.bonus_points', 0);
        $this->getJson($base.'/reports/monthly/archives/'.$archive.'/download')->assertOk();
        $this->assertSame($hash, hash_file('sha256', Storage::disk('reports')->path($report->file_path)));
        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'project.reporting_changed', 'subject_id' => $project->id]);
    }

    public function test_report_marks_planned_tasks_and_exports_the_selected_year_without_duplicates(): void
    {
        Storage::fake('reports');
        [$user, $scope, $base] = $this->workspace();
        $task = Task::factory()->create(['scope_id' => $scope->id, 'project_id' => null, 'created_by' => $user->id, 'assignee_id' => $user->id, 'status' => 'done', 'completed_at' => '2026-09-15 10:00:00']);
        foreach (['2026-01', '2026-12', '2027-01'] as $month) {
            $plan = PlanItem::factory()->create(['scope_id' => $scope->id, 'created_by' => $user->id, 'month' => $month, 'title' => 'План '.$month]);
            $plan->tasks()->attach($task->id);
        }
        $filters = ['month' => '2026-09', 'timezone' => 'Europe/Moscow', 'plan_year' => 2026];
        $this->getJson($base.'/reports/monthly?'.http_build_query($filters))->assertOk()->assertJsonCount(1, 'data.completed')->assertJsonPath('data.completed.0.planned', true)->assertJsonCount(2, 'data.plan_items');
        $id = $this->postJson($base.'/reports/monthly/archives', $filters)->assertCreated()->json('data.id');
        $report = MonthlyReport::findOrFail($id);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('reports')->path($report->file_path)));
        $xml = $zip->getFromName('xl/worksheets/sheet4.xml');
        $this->assertStringContainsString('План 2026-01', $xml);
        $this->assertStringContainsString('План 2026-12', $xml);
        $this->assertStringNotContainsString('План 2027-01', $xml);
        $zip->close();
    }

    public function test_only_owner_or_project_creator_can_change_reporting_flag(): void
    {
        [$owner, $scope, $base] = $this->workspace();
        $project = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'scope']);
        $member = User::factory()->create();
        $scope->members()->create(['user_id' => $member->id, 'role' => 'member', 'project_access_mode' => 'all']);
        $this->actingAs($member)->patchJson($base.'/projects/'.$project->id, ['include_in_reports' => false])->assertForbidden();
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'include_in_reports' => true]);
    }

    public function test_moved_tasks_stop_contributing_without_blocking_manual_completion(): void
    {
        [$user, $scope, $base] = $this->workspace();
        $task = Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $user->id, 'project_id' => null, 'status' => 'done']);
        $plan = PlanItem::factory()->create(['scope_id' => $scope->id, 'created_by' => $user->id]);
        $plan->tasks()->attach($task->id);
        $project = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $user->id]);
        $task->update(['project_id' => $project->id]);
        $this->getJson($base.'/plans/'.$plan->id)->assertOk()->assertJsonPath('data.tasks_count', 0);
        $this->patchJson($base.'/plans/'.$plan->id, ['completed' => true])->assertOk()->assertJsonPath('data.completed_by', $user->id);
    }

    public function test_missing_authentication_is_rejected_and_list_filters_are_applied(): void
    {
        [$user, $scope, $base] = $this->workspace();
        PlanItem::factory()->create(['scope_id' => $scope->id, 'created_by' => $user->id, 'assignee_id' => $user->id, 'month' => '2026-10', 'completed_at' => now()]);
        PlanItem::factory()->create(['scope_id' => $scope->id, 'created_by' => $user->id, 'month' => '2026-09']);
        $this->getJson($base.'/plans?year=2026&month=2026-10&status=done&assignee_id='.$user->id)
            ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.done', 1)->assertJsonCount(1, 'data.items');
        $this->app['auth']->forgetGuards();
        $this->getJson($base.'/plans?year=2026')->assertUnauthorized();
    }
}
