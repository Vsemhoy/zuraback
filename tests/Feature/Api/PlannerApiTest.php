<?php

namespace Tests\Feature\Api;

use App\Models\Scope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlannerApiTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-App-Request' => 'Zuratax'];

    public function test_tasks_and_tails_are_planned_copied_and_bulk_edited(): void
    {
        $owner = User::factory()->create();
        $scope = Scope::query()->create(['owner_id' => $owner->id, 'name' => 'Work', 'slug' => 'work']);
        $scope->members()->create(['user_id' => $owner->id, 'role' => 'owner', 'joined_at' => now()]);
        $project = $this->actingAs($owner)->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/projects", [
            'title' => 'Administration', 'key' => 'ADM', 'color' => '#D97706',
        ])->assertCreated()->json('data');
        $task = $this->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/tasks", [
            'title' => 'Prepare release', 'project_id' => $project['id'], 'status' => 'scheduled', 'due_at' => '2026-09-02 12:00:00',
        ])->assertCreated()->json('data');
        $this->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/tasks/{$task['id']}/checklist", ['title' => 'Check backup'])->assertCreated();

        $tail = $this->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/planner/tails", [
            'task_id' => $task['id'], 'planned_on' => '2026-09-04',
        ])->assertCreated()->assertJsonPath('data.task.task_key', 'ADM-1')->json('data');
        $this->withHeaders(self::HEADERS)->getJson("/api/scopes/{$scope->id}/planner?from=2026-09-01&to=2026-09-30")
            ->assertOk()->assertJsonPath('data.tasks.0.id', $task['id'])->assertJsonPath('data.tails.0.id', $tail['id']);
        $copy = $this->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/planner/tasks/{$task['id']}/copy", ['planned_on' => '2026-09-07'])
            ->assertCreated()->assertJsonPath('data.task_key', 'ADM-2')->json('data');
        $this->assertDatabaseHas('task_checklist_items', ['task_id' => $copy['id'], 'title' => 'Check backup']);

        $this->withHeaders(self::HEADERS)->patchJson("/api/scopes/{$scope->id}/planner/tasks/bulk", [
            'task_ids' => [$task['id'], $copy['id']], 'status' => 'in_progress', 'priority' => 5,
            'description' => 'September focus', 'checklist_item' => 'Notify manager',
        ])->assertOk()->assertJsonPath('data.updated', 2);
        $this->assertDatabaseHas('tasks', ['id' => $task['id'], 'status' => 'in_progress', 'priority' => 5, 'description' => 'September focus']);
        $this->assertDatabaseHas('task_checklist_items', ['task_id' => $copy['id'], 'title' => 'Notify manager']);

        $this->withHeaders(self::HEADERS)->patchJson("/api/scopes/{$scope->id}/planner/tails/{$tail['id']}", ['planned_on' => '2026-09-08'])
            ->assertOk()->assertJsonPath('data.planned_on', '2026-09-08');
    }
}
