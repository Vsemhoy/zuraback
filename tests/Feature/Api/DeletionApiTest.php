<?php

namespace Tests\Feature\Api;

use App\Models\Scope;
use App\Models\Task;
use App\Models\TaskPlannerTail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeletionApiTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-App-Request' => 'Zuratax'];

    public function test_owner_can_soft_delete_test_entities_without_destroying_linked_content(): void
    {
        $owner = User::factory()->create();
        $scope = Scope::query()->create(['owner_id' => $owner->id, 'name' => 'Cleanup', 'slug' => 'cleanup']);
        $scope->members()->create(['user_id' => $owner->id, 'role' => 'owner', 'joined_at' => now()]);
        $project = $scope->projects()->create(['created_by' => $owner->id, 'title' => 'Test project', 'key' => 'TST']);
        $projectTask = $this->task($scope, $owner, ['project_id' => $project->id, 'task_key' => 'TST-1']);
        $projectBook = $scope->books()->create(['created_by' => $owner->id, 'project_id' => $project->id, 'title' => 'Project book', 'slug' => 'project-book']);

        $this->actingAs($owner)->withHeaders(self::HEADERS)
            ->deleteJson("/api/scopes/{$scope->id}/projects/{$project->id}")
            ->assertNoContent();
        $this->assertSoftDeleted('projects', ['id' => $project->id]);
        $this->assertDatabaseHas('tasks', ['id' => $projectTask->id, 'project_id' => null]);
        $this->assertDatabaseHas('books', ['id' => $projectBook->id, 'project_id' => null]);

        $task = $this->task($scope, $owner, ['task_key' => 'TSK-2']);
        $child = $this->task($scope, $owner, ['task_key' => 'TSK-3', 'parent_id' => $task->id]);
        TaskPlannerTail::query()->create(['scope_id' => $scope->id, 'task_id' => $task->id, 'created_by' => $owner->id, 'planned_on' => now()->addDay()]);
        $this->withHeaders(self::HEADERS)->deleteJson("/api/scopes/{$scope->id}/tasks/{$task->id}")->assertNoContent();
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'cancelled', 'deleted_at' => null]);
        $this->assertDatabaseHas('tasks', ['id' => $child->id, 'parent_id' => $task->id]);
        $this->withHeaders(self::HEADERS)->deleteJson("/api/scopes/{$scope->id}/tasks/{$task->id}")->assertNoContent();
        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
        $this->assertDatabaseHas('tasks', ['id' => $child->id, 'parent_id' => null]);
        $this->assertDatabaseMissing('task_planner_tails', ['task_id' => $task->id]);

        $trashOne = $this->task($scope, $owner, ['task_key' => 'TSK-20', 'status' => 'cancelled']);
        $trashTwo = $this->task($scope, $owner, ['task_key' => 'TSK-21', 'status' => 'cancelled']);
        $this->withHeaders(self::HEADERS)->deleteJson("/api/scopes/{$scope->id}/tasks/trash")
            ->assertOk()->assertJsonPath('data.deleted_count', 2);
        $this->assertDatabaseMissing('tasks', ['id' => $trashOne->id]);
        $this->assertDatabaseMissing('tasks', ['id' => $trashTwo->id]);

        $book = $scope->books()->create(['created_by' => $owner->id, 'title' => 'Disposable book', 'slug' => 'disposable-book']);
        $page = $book->pages()->create(['created_by' => $owner->id, 'title' => 'Page', 'slug' => 'page']);
        $this->withHeaders(self::HEADERS)->deleteJson("/api/scopes/{$scope->id}/books/{$book->id}")->assertNoContent();
        $this->assertSoftDeleted('books', ['id' => $book->id]);
        $this->assertDatabaseHas('book_pages', ['id' => $page->id]);

        $contractor = User::factory()->virtual()->create(['created_by' => $owner->id]);
        $scope->members()->create(['user_id' => $contractor->id, 'role' => 'member', 'joined_at' => now()]);
        $assigned = $this->task($scope, $owner, ['task_key' => 'TSK-4', 'assignee_id' => $contractor->id]);
        $this->withHeaders(self::HEADERS)->deleteJson("/api/scopes/{$scope->id}/contractors/{$contractor->id}")->assertNoContent();
        $this->assertSoftDeleted('users', ['id' => $contractor->id]);
        $this->assertDatabaseHas('scope_members', ['scope_id' => $scope->id, 'user_id' => $contractor->id, 'is_active' => false]);
        $this->assertDatabaseHas('tasks', ['id' => $assigned->id, 'assignee_id' => null]);
    }

    public function test_scope_owner_and_current_account_cannot_be_deleted(): void
    {
        $owner = User::factory()->create();
        $scope = Scope::query()->create(['owner_id' => $owner->id, 'name' => 'Protected', 'slug' => 'protected']);
        $scope->members()->create(['user_id' => $owner->id, 'role' => 'owner', 'joined_at' => now()]);

        $this->actingAs($owner)->withHeaders(self::HEADERS)
            ->deleteJson("/api/scopes/{$scope->id}/contractors/{$owner->id}")
            ->assertUnprocessable();
        $this->assertNotSoftDeleted($owner);
    }

    /** @param array<string, mixed> $attributes */
    private function task(Scope $scope, User $owner, array $attributes): Task
    {
        return $scope->tasks()->create([
            'created_by' => $owner->id,
            'number' => 1,
            'title' => 'Disposable task',
            ...$attributes,
        ]);
    }
}
