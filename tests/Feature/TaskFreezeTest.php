<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Scope;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TaskFreezeTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(string $status): array
    {
        $owner = User::factory()->create();
        $scope = Scope::factory()->create(['owner_id' => $owner->id]);
        $scope->members()->create(['user_id' => $owner->id, 'role' => 'owner', 'joined_at' => now()]);
        $task = Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'status' => $status, 'title' => 'Keep title', 'description' => 'Keep description', 'result' => 'Keep result', 'agent_notes' => 'Keep notes', 'completed_at' => $status === 'done' ? now() : null]);
        $this->actingAs($owner)->withHeaders(['Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-App-Request' => 'Zuratax']);

        return [$owner, $scope, $task, "/api/scopes/{$scope->id}/tasks/{$task->id}"];
    }

    public static function frozenFields(): array
    {
        $cases = [];
        foreach (['done', 'cancelled'] as $status) {
            foreach (['title' => 'Changed', 'description' => null, 'result' => null, 'agent_notes' => null, 'priority' => 5, 'due_at' => '2026-11-01'] as $field => $value) {
                $cases[$status.' '.$field] = [$status, $field, $value];
            }
        }

        return $cases;
    }

    #[DataProvider('frozenFields')]
    public function test_closed_tasks_refuse_changes_to_frozen_fields_with_422(string $status, string $field, mixed $value): void
    {
        [, , $task, $url] = $this->workspace($status);
        $original = $task->fresh()->getRawOriginal($field);
        $this->patchJson($url, [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($original, $task->fresh()->getRawOriginal($field));
    }

    public function test_reopening_and_erasing_content_cannot_be_combined_but_separate_reopening_allows_edits(): void
    {
        [, , $task, $url] = $this->workspace('done');
        $this->patchJson($url, ['status' => 'todo', 'description' => null])->assertUnprocessable()->assertJsonValidationErrors('description');
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'done', 'description' => 'Keep description']);
        $this->patchJson($url, ['status' => 'todo'])->assertOk()->assertJsonPath('data.completed_at', null);
        $this->patchJson($url, ['description' => 'Corrected'])->assertOk()->assertJsonPath('data.description', 'Corrected');
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'todo', 'description' => 'Corrected']);
    }

    public function test_done_task_allows_project_and_kpi_corrections_but_deleted_task_does_not(): void
    {
        [$owner, $scope, $task, $url] = $this->workspace('done');
        $project = $scope->projects()->create(['title' => 'Correction', 'key' => 'FIX', 'created_by' => $owner->id]);
        $kpi = $scope->kpis()->create(['name' => 'Correct KPI', 'kind' => 'bonus', 'points' => 10, 'minimum_completed_tasks' => 1, 'created_by' => $owner->id]);
        $this->patchJson($url, ['project_id' => $project->id, 'kpi_id' => $kpi->id])->assertOk()->assertJsonPath('data.kpi_id', $kpi->id);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'project_id' => $project->id, 'kpi_id' => $kpi->id, 'description' => 'Keep description']);
        $this->deleteJson($url)->assertNoContent();
        $this->patchJson($url, ['kpi_id' => null])->assertUnprocessable()->assertJsonValidationErrors('kpi_id');
        $this->patchJson($url, ['status' => 'todo'])->assertOk();
        $this->patchJson($url, ['kpi_id' => null])->assertOk();
    }

    public function test_bulk_edit_is_atomic_when_a_selected_task_is_frozen(): void
    {
        [$owner, $scope, $task] = $this->workspace('done');
        $open = Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'description' => 'Open content']);
        $this->patchJson("/api/scopes/{$scope->id}/planner/tasks/bulk", ['task_ids' => [$open->id, $task->id], 'description' => 'Overwrite'])->assertUnprocessable();
        $this->assertDatabaseHas('tasks', ['id' => $open->id, 'description' => 'Open content']);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'description' => 'Keep description']);
    }

    public function test_department_corrections_are_allowed_for_done_tasks_but_not_deleted_tasks(): void
    {
        [, $scope, $task, $url] = $this->workspace('done');
        $department = Department::factory()->create(['scope_id' => $scope->id]);

        $this->patchJson($url, ['department_id' => $department->id])->assertOk()->assertJsonPath('data.department_id', $department->id);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'department_id' => $department->id, 'description' => 'Keep description']);

        $this->deleteJson($url)->assertNoContent();
        $this->patchJson($url, ['department_id' => null])->assertUnprocessable()->assertJsonValidationErrors('department_id');
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'department_id' => $department->id, 'status' => 'cancelled']);
    }

    public function test_done_task_cannot_receive_checklist_changes_or_new_subtasks(): void
    {
        [, $scope, $task, $url] = $this->workspace('done');
        $this->postJson($url.'/checklist', ['title' => 'Extra work'])->assertUnprocessable();
        $this->postJson("/api/scopes/{$scope->id}/tasks", ['parent_id' => $task->id, 'title' => 'Extra work'])->assertUnprocessable();
        $this->assertDatabaseCount('task_checklist_items', 0);
        $this->assertDatabaseCount('tasks', 1);
    }

    public function test_generic_links_and_parent_assignment_cannot_change_a_closed_task(): void
    {
        [$owner, $scope, $task] = $this->workspace('todo');
        $other = Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $url = "/api/scopes/{$scope->id}/links";
        $payload = ['source_type' => 'task', 'source_id' => $task->id, 'target_type' => 'task', 'target_id' => $other->id];
        $id = $this->postJson($url, $payload)->assertCreated()->json('data.id');
        $task->update(['status' => 'done']);
        $this->deleteJson("{$url}/{$id}")->assertUnprocessable();
        $this->postJson($url, $payload)->assertUnprocessable();
        $this->patchJson("/api/scopes/{$scope->id}/tasks/{$other->id}", ['parent_id' => $task->id])->assertUnprocessable();
        $this->assertDatabaseHas('tasks', ['id' => $other->id, 'parent_id' => null]);
        $this->assertDatabaseHas('entity_links', ['id' => $id]);
    }

    public function test_agent_api_enforces_the_same_content_freeze(): void
    {
        [, $scope, $task] = $this->workspace('done');
        $agent = User::factory()->create(['type' => 'agent', 'status' => 'active', 'is_active' => true]);
        $scope->members()->create(['user_id' => $agent->id, 'role' => 'admin', 'is_active' => true, 'project_access_mode' => 'all']);
        auth()->guard('web')->logout();
        auth()->forgetGuards();
        $this->withToken($agent->createToken('Editor', ['task.view', 'task.update'])->plainTextToken)
            ->patchJson("/api/agent/scopes/{$scope->id}/tasks/{$task->id}", ['agent_notes' => null])->assertUnprocessable();
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'agent_notes' => 'Keep notes']);
    }
}
