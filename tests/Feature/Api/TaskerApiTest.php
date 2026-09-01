<?php

namespace Tests\Feature\Api;

use App\Models\ActivityLog;
use App\Models\EntityLink;
use App\Models\Scope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskerApiTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-App-Request' => 'Zuratax'];

    public function test_projects_are_colored_and_returned_in_configured_order(): void
    {
        [$user, $scope] = $this->workspace();

        $second = $this->actingAs($user)->withHeaders(self::HEADERS)
            ->postJson("/api/scopes/{$scope->id}/projects", [
                'title' => 'Second project', 'key' => 'SEC', 'color' => '#D97706', 'sort_order' => 20,
            ])->assertCreated()->assertJsonPath('data.color', '#D97706')->json('data');
        $first = $this->withHeaders(self::HEADERS)
            ->postJson("/api/scopes/{$scope->id}/projects", [
                'title' => 'First project', 'key' => 'FST', 'sort_order' => 10,
            ])->assertCreated()->assertJsonPath('data.color', '#2668D8')->json('data');

        $this->withHeaders(self::HEADERS)
            ->getJson("/api/scopes/{$scope->id}/projects")
            ->assertOk()
            ->assertJsonPath('data.0.id', $first['id'])
            ->assertJsonPath('data.1.id', $second['id']);
        $this->withHeaders(self::HEADERS)
            ->patchJson("/api/scopes/{$scope->id}/projects/{$first['id']}", ['color' => '#16A34A'])
            ->assertOk()->assertJsonPath('data.color', '#16A34A');

        $this->assertDatabaseHas('projects', ['id' => $first['id'], 'color' => '#16A34A']);
    }

    public function test_project_color_returns_422_for_invalid_hex_value(): void
    {
        [$user, $scope] = $this->workspace();

        $this->actingAs($user)->withHeaders(self::HEADERS)
            ->postJson("/api/scopes/{$scope->id}/projects", [
                'title' => 'Bad color', 'key' => 'BAD', 'color' => 'red',
            ])->assertUnprocessable()->assertJsonValidationErrors('color');

        $this->assertDatabaseMissing('projects', ['scope_id' => $scope->id, 'key' => 'BAD']);
    }

    public function test_project_and_scope_task_keys_are_allocated_sequentially(): void
    {
        [$user, $scope] = $this->workspace();

        $project = $this->actingAs($user)->withHeaders(self::HEADERS)
            ->postJson("/api/scopes/{$scope->id}/projects", ['title' => 'Administration', 'key' => 'adm'])
            ->assertCreated()->assertJsonPath('data.key', 'ADM')->json('data');

        $this->withHeaders(self::HEADERS)
            ->postJson("/api/scopes/{$scope->id}/tasks", ['project_id' => $project['id'], 'title' => 'First'])
            ->assertCreated()->assertJsonPath('data.task_key', 'ADM-1')->assertJsonPath('data.number', 1)
            ->assertJsonPath('data.project.color', '#2668D8');

        $this->withHeaders(self::HEADERS)
            ->postJson("/api/scopes/{$scope->id}/tasks", ['project_id' => $project['id'], 'title' => 'Second'])
            ->assertCreated()->assertJsonPath('data.task_key', 'ADM-2')->assertJsonPath('data.number', 2);

        $this->withHeaders(self::HEADERS)
            ->postJson("/api/scopes/{$scope->id}/tasks", ['title' => 'Unprojected'])
            ->assertCreated()->assertJsonPath('data.task_key', 'TSK-1');

        $this->withHeaders(self::HEADERS)
            ->getJson("/api/scopes/{$scope->id}/tasks")
            ->assertOk()->assertJsonPath('data.0.project.color', '#2668D8');
    }

    public function test_task_board_returns_more_than_the_default_pagination_page(): void
    {
        [$user, $scope] = $this->workspace();

        foreach (range(1, 18) as $number) {
            $scope->tasks()->create([
                'created_by' => $user->id,
                'task_key' => "TSK-{$number}",
                'number' => $number,
                'title' => "Task {$number}",
            ]);
        }

        $this->actingAs($user)->withHeaders(self::HEADERS)
            ->getJson("/api/scopes/{$scope->id}/tasks")
            ->assertOk()
            ->assertJsonCount(18, 'data')
            ->assertJsonFragment(['task_key' => 'TSK-18']);
    }

    public function test_checklist_completion_is_timestamped_and_reopening_is_audited(): void
    {
        [$user, $scope] = $this->workspace();
        $task = $this->actingAs($user)->withHeaders(self::HEADERS)
            ->postJson("/api/scopes/{$scope->id}/tasks", ['title' => 'Prepare backup policy'])
            ->assertCreated()->json('data');

        $item = $this->withHeaders(self::HEADERS)
            ->postJson("/api/scopes/{$scope->id}/tasks/{$task['id']}/checklist", ['title' => 'Collect requirements'])
            ->assertCreated()->json('data');

        $this->withHeaders(self::HEADERS)
            ->patchJson("/api/scopes/{$scope->id}/tasks/{$task['id']}/checklist/{$item['id']}", ['is_completed' => true])
            ->assertOk()->assertJsonPath('data.completed_by_id', $user->id)->assertJsonPath('data.completed_by.id', $user->id);

        $this->assertDatabaseHas('task_checklist_items', ['id' => $item['id'], 'completed_by' => $user->id]);

        $this->withHeaders(self::HEADERS)
            ->patchJson("/api/scopes/{$scope->id}/tasks/{$task['id']}/checklist/{$item['id']}", ['is_completed' => false])
            ->assertOk()->assertJsonPath('data.completed_at', null)->assertJsonPath('data.completed_by_id', null);

        $this->assertSame(2, ActivityLog::query()->where('subject_id', $item['id'])->count());
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $item['id'], 'action' => 'checklist_item.completed']);
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $item['id'], 'action' => 'checklist_item.reopened']);
    }

    public function test_blocker_is_audited_restores_status_and_keeps_history(): void
    {
        [$user, $scope] = $this->workspace();
        $task = $this->actingAs($user)->withHeaders(self::HEADERS)
            ->postJson("/api/scopes/{$scope->id}/tasks", ['title' => 'Fix 1C bug', 'status' => 'in_progress'])
            ->assertCreated()->json('data');

        $blocker = $this->withHeaders(self::HEADERS)
            ->postJson("/api/scopes/{$scope->id}/tasks/{$task['id']}/blockers", [
                'reason' => 'No administrative access to 1C',
                'resolution_required' => 'Access or action by an authorized specialist',
                'responsible_text' => 'System administrator',
            ])->assertCreated()
            ->assertJsonPath('data.previous_status', 'in_progress')
            ->assertJsonPath('data.is_active', true)
            ->json('data');

        $this->assertDatabaseHas('tasks', ['id' => $task['id'], 'status' => 'blocked']);
        $this->withHeaders(self::HEADERS)
            ->postJson("/api/scopes/{$scope->id}/tasks/{$task['id']}/blockers", [
                'reason' => 'Another reason', 'resolution_required' => 'Another action', 'responsible_text' => 'Manager',
            ])->assertUnprocessable();
        $this->withHeaders(self::HEADERS)
            ->patchJson("/api/scopes/{$scope->id}/tasks/{$task['id']}", ['status' => 'todo'])
            ->assertUnprocessable();

        $this->withHeaders(self::HEADERS)
            ->patchJson("/api/scopes/{$scope->id}/tasks/{$task['id']}/blockers/{$blocker['id']}/resolve", ['resolution_note' => 'Temporary access granted'])
            ->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.resolved_by.id', $user->id);

        $this->assertDatabaseHas('tasks', ['id' => $task['id'], 'status' => 'in_progress']);
        $this->assertDatabaseCount('task_blockers', 1);
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $task['id'], 'action' => 'task.blocked']);
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $task['id'], 'action' => 'task.unblocked']);

        $this->withHeaders(self::HEADERS)
            ->postJson("/api/scopes/{$scope->id}/tasks/{$task['id']}/blockers", [
                'reason' => 'Production window is closed', 'resolution_required' => 'Approve a maintenance window', 'responsible_text' => 'Manager',
            ])->assertCreated();
        $this->assertDatabaseCount('task_blockers', 2);
    }

    public function test_task_can_be_atomically_moved_and_blocked_task_cannot_be_dragged(): void
    {
        [$user, $scope] = $this->workspace();
        $first = $this->actingAs($user)->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/tasks", ['title' => 'First'])->assertCreated()->json('data');
        $second = $this->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/tasks", ['title' => 'Second', 'status' => 'in_progress'])->assertCreated()->json('data');

        $this->withHeaders(self::HEADERS)
            ->patchJson("/api/scopes/{$scope->id}/tasks/{$first['id']}/move", ['status' => 'in_progress', 'target_index' => 0])
            ->assertOk()->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.sort_order', 1000);
        $this->assertDatabaseHas('tasks', ['id' => $second['id'], 'status' => 'in_progress', 'sort_order' => 2000]);
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $first['id'], 'action' => 'task.moved']);

        $blocker = $this->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/tasks/{$first['id']}/blockers", [
            'reason' => 'No access', 'resolution_required' => 'Grant access', 'responsible_text' => 'Administrator',
        ])->assertCreated()->json('data');
        $this->withHeaders(self::HEADERS)
            ->patchJson("/api/scopes/{$scope->id}/tasks/{$first['id']}/move", ['status' => 'done', 'target_index' => 0])
            ->assertUnprocessable();
        $this->assertNotNull($blocker['id']);
    }

    public function test_task_can_be_scheduled_and_moved_to_deleted_column(): void
    {
        [$user, $scope] = $this->workspace();
        $task = $this->actingAs($user)->withHeaders(self::HEADERS)
            ->postJson("/api/scopes/{$scope->id}/tasks", ['title' => 'Prepare maintenance', 'status' => 'scheduled'])
            ->assertCreated()->assertJsonPath('data.status', 'scheduled')->json('data');

        $this->withHeaders(self::HEADERS)
            ->patchJson("/api/scopes/{$scope->id}/tasks/{$task['id']}/move", ['status' => 'cancelled', 'target_index' => 0])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('tasks', ['id' => $task['id'], 'status' => 'cancelled']);
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $task['id'], 'action' => 'task.moved']);
    }

    public function test_completing_an_unassigned_task_automatically_assigns_the_actor(): void
    {
        [$user, $scope] = $this->workspace();
        $updated = $this->actingAs($user)->withHeaders(self::HEADERS)
            ->postJson("/api/scopes/{$scope->id}/tasks", ['title' => 'Complete from editor'])
            ->assertCreated()->json('data');
        $dragged = $this->withHeaders(self::HEADERS)
            ->postJson("/api/scopes/{$scope->id}/tasks", ['title' => 'Complete by drag'])
            ->assertCreated()->json('data');

        $this->withHeaders(self::HEADERS)
            ->patchJson("/api/scopes/{$scope->id}/tasks/{$updated['id']}", ['status' => 'done'])
            ->assertOk()->assertJsonPath('data.assignee.id', $user->id);
        $this->withHeaders(self::HEADERS)
            ->patchJson("/api/scopes/{$scope->id}/tasks/{$dragged['id']}/move", ['status' => 'done', 'target_index' => 0])
            ->assertOk()->assertJsonPath('data.assignee.id', $user->id);

        $this->assertDatabaseHas('tasks', ['id' => $updated['id'], 'status' => 'done', 'assignee_id' => $user->id]);
        $this->assertDatabaseHas('tasks', ['id' => $dragged['id'], 'status' => 'done', 'assignee_id' => $user->id]);
    }

    public function test_checklist_item_can_be_converted_and_subtask_can_be_detached(): void
    {
        [$user, $scope] = $this->workspace();
        $parent = $this->actingAs($user)->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/tasks", ['title' => 'Parent'])->assertCreated()->json('data');
        $item = $this->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/tasks/{$parent['id']}/checklist", ['title' => 'Growing step'])->assertCreated()->json('data');

        $subtask = $this->withHeaders(self::HEADERS)
            ->postJson("/api/scopes/{$scope->id}/tasks/{$parent['id']}/checklist/{$item['id']}/convert-to-subtask")
            ->assertCreated()->assertJsonPath('data.parent_id', $parent['id'])->assertJsonPath('data.title', 'Growing step')->json('data');
        $this->assertDatabaseMissing('task_checklist_items', ['id' => $item['id']]);
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $subtask['id'], 'action' => 'checklist_item.converted_to_subtask']);

        $this->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/tasks/{$subtask['id']}/detach")
            ->assertOk()->assertJsonPath('data.parent_id', null);
        $this->assertTrue(EntityLink::query()->where('relation', 'related')->where(function ($query) use ($subtask, $parent): void {
            $query->where(fn ($side) => $side->where('source_id', $subtask['id'])->where('target_id', $parent['id']))
                ->orWhere(fn ($side) => $side->where('source_id', $parent['id'])->where('target_id', $subtask['id']));
        })->exists());
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $subtask['id'], 'action' => 'task.detached']);
    }

    public function test_task_relations_are_created_by_human_key_and_presented_from_current_perspective(): void
    {
        [$user, $scope] = $this->workspace();
        $first = $this->actingAs($user)->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/tasks", ['title' => 'Needs data'])->assertCreated()->json('data');
        $second = $this->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/tasks", ['title' => 'Provide data'])->assertCreated()->json('data');

        $link = $this->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/tasks/{$first['id']}/relations", [
            'task_key' => strtolower($second['task_key']), 'relation' => 'blocked_by',
        ])->assertCreated()->assertJsonPath('data.relation', 'blocked_by')->assertJsonPath('data.task.task_key', $second['task_key'])->json('data');

        $this->withHeaders(self::HEADERS)->getJson("/api/scopes/{$scope->id}/tasks/{$second['id']}/relations")
            ->assertOk()->assertJsonPath('data.0.relation', 'blocks')->assertJsonPath('data.0.task.task_key', $first['task_key']);
        $this->withHeaders(self::HEADERS)->getJson("/api/scopes/{$scope->id}/tasks/search?q=Needs")
            ->assertOk()->assertJsonPath('data.0.id', $first['id']);
        $this->withHeaders(self::HEADERS)->deleteJson("/api/scopes/{$scope->id}/tasks/{$first['id']}/relations/{$link['id']}")->assertNoContent();
        $this->assertDatabaseMissing('entity_links', ['id' => $link['id']]);
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $first['id'], 'action' => 'task.relation_deleted']);
    }

    public function test_task_comments_and_immutable_activity_are_available_separately(): void
    {
        [$user, $scope] = $this->workspace();
        $task = $this->actingAs($user)->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/tasks", ['title' => 'Discuss implementation'])->assertCreated()->json('data');
        $comment = $this->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/tasks/{$task['id']}/comments", ['content' => 'We need administrative access.'])
            ->assertCreated()->assertJsonPath('data.created_by.id', $user->id)->json('data');
        $this->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/tasks/{$task['id']}/comments", ['content' => 'Agreed.', 'parent_id' => $comment['id']])
            ->assertCreated()->assertJsonPath('data.parent_id', $comment['id']);
        $this->withHeaders(self::HEADERS)->getJson("/api/scopes/{$scope->id}/tasks/{$task['id']}/comments")
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.content', 'We need administrative access.');
        $this->withHeaders(self::HEADERS)->getJson("/api/scopes/{$scope->id}/tasks/{$task['id']}/activity")
            ->assertOk()->assertJsonPath('data.0.action', 'task.comment_created')->assertJsonPath('data.0.actor.id', $user->id);
    }

    /** @return array{User, Scope} */
    private function workspace(): array
    {
        $user = User::factory()->create();
        $scope = Scope::query()->create(['owner_id' => $user->id, 'name' => 'Work', 'slug' => 'work']);
        $scope->members()->create(['user_id' => $user->id, 'role' => 'owner', 'joined_at' => now()]);

        return [$user, $scope];
    }
}
