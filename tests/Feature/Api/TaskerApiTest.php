<?php

namespace Tests\Feature\Api;

use App\Models\ActivityLog;
use App\Models\EntityLink;
use App\Models\Scope;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskerApiTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-App-Request' => 'Zuratax'];

    public function test_private_projects_and_their_tasks_are_hidden_from_scope_colleagues(): void
    {
        [$owner, $scope] = $this->workspace();
        $colleague = User::factory()->create();
        $scope->members()->create([
            'user_id' => $colleague->id,
            'role' => 'member',
            'project_access_mode' => 'all',
            'joined_at' => now(),
        ]);

        $private = $this->actingAs($owner)->withHeaders(self::HEADERS)
            ->postJson("/api/scopes/{$scope->id}/projects", ['title' => 'Personal', 'key' => 'PER'])
            ->assertCreated()
            ->assertJsonPath('data.visibility', 'private')
            ->assertJsonPath('data.creator.id', $owner->id)
            ->json('data');
        $shared = $this->withHeaders(self::HEADERS)
            ->postJson("/api/scopes/{$scope->id}/projects", ['title' => 'Shared', 'key' => 'SHR', 'visibility' => 'scope'])
            ->assertCreated()
            ->json('data');

        $this->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/tasks", [
            'project_id' => $private['id'], 'title' => 'Private task',
        ])->assertCreated();
        $this->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/tasks", [
            'project_id' => $shared['id'], 'title' => 'Shared task',
        ])->assertCreated();

        $this->actingAs($colleague)->withHeaders(self::HEADERS)
            ->getJson("/api/scopes/{$scope->id}/projects")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $shared['id']);
        $this->withHeaders(self::HEADERS)
            ->getJson("/api/scopes/{$scope->id}/tasks")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Shared task');
        $this->withHeaders(self::HEADERS)
            ->getJson("/api/scopes/{$scope->id}/projects/{$private['id']}")
            ->assertNotFound();
    }

    public function test_projects_can_be_reordered_for_every_project_list(): void
    {
        [$user, $scope] = $this->workspace();
        $first = $scope->projects()->create(['created_by' => $user->id, 'title' => 'First', 'key' => 'FST']);
        $second = $scope->projects()->create(['created_by' => $user->id, 'title' => 'Second', 'key' => 'SEC']);

        $this->actingAs($user)->withHeaders(self::HEADERS)
            ->patchJson("/api/scopes/{$scope->id}/projects/reorder", ['project_ids' => [$second->id, $first->id]])
            ->assertOk()
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.1.id', $first->id);

        $this->assertDatabaseHas('projects', ['id' => $second->id, 'sort_order' => 1000]);
        $this->assertDatabaseHas('projects', ['id' => $first->id, 'sort_order' => 2000]);
    }

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

    public function test_executor_is_assigned_to_a_task_they_create_and_checklist_items_can_be_edited_and_deleted(): void
    {
        [$user, $scope] = $this->workspace();

        $task = $this->actingAs($user)->withHeaders(self::HEADERS)
            ->postJson("/api/scopes/{$scope->id}/tasks", ['title' => 'Prepare release', 'assignee_id' => null])
            ->assertCreated()
            ->assertJsonPath('data.assignee.id', $user->id)
            ->json('data');

        $item = $this->withHeaders(self::HEADERS)
            ->postJson("/api/scopes/{$scope->id}/tasks/{$task['id']}/checklist", ['title' => 'Draft notes'])
            ->assertCreated()
            ->json('data');

        $this->withHeaders(self::HEADERS)
            ->patchJson("/api/scopes/{$scope->id}/tasks/{$task['id']}/checklist/{$item['id']}", [
                'title' => 'Publish notes',
                'assignee_id' => $user->id,
                'due_at' => now()->addDay()->toIso8601String(),
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Publish notes')
            ->assertJsonPath('data.assignee.id', $user->id);

        $this->withHeaders(self::HEADERS)
            ->deleteJson("/api/scopes/{$scope->id}/tasks/{$task['id']}/checklist/{$item['id']}")
            ->assertNoContent();

        $this->assertDatabaseMissing('task_checklist_items', ['id' => $item['id']]);
    }

    public function test_non_executor_is_not_automatically_assigned_to_a_task(): void
    {
        [$user, $scope] = $this->workspace();
        $user->update(['is_executor' => false]);

        $this->actingAs($user)->withHeaders(self::HEADERS)
            ->postJson("/api/scopes/{$scope->id}/tasks", ['title' => 'Manager task', 'assignee_id' => null])
            ->assertCreated()
            ->assertJsonPath('data.assignee', null);
    }

    public function test_agent_notes_are_stored_as_a_separate_task_document(): void
    {
        [$user, $scope] = $this->workspace();

        $task = $this->actingAs($user)->withHeaders(self::HEADERS)
            ->postJson("/api/scopes/{$scope->id}/tasks", [
                'title' => 'Investigate integration',
                'agent_notes' => "## Agent context\n\nInitial hypothesis.",
            ])
            ->assertCreated()
            ->assertJsonPath('data.agent_notes', "## Agent context\n\nInitial hypothesis.")
            ->json('data');

        $this->withHeaders(self::HEADERS)
            ->patchJson("/api/scopes/{$scope->id}/tasks/{$task['id']}", [
                'agent_notes' => "## Agent answer\n\nThe integration is healthy.",
            ])
            ->assertOk()
            ->assertJsonPath('data.agent_notes', "## Agent answer\n\nThe integration is healthy.");

        $this->assertDatabaseHas('tasks', [
            'id' => $task['id'],
            'agent_notes' => "## Agent answer\n\nThe integration is healthy.",
        ]);
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

    public function test_tasks_can_be_created_below_a_deep_parent_without_changing_ancestor_content(): void
    {
        [$user, $scope] = $this->workspace();
        $root = Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $user->id, 'description' => 'Keep root description', 'result' => 'Keep root result', 'agent_notes' => 'Keep root notes']);
        $parent = $root;
        for ($level = 0; $level < 25; $level++) {
            $parent = Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $user->id, 'parent_id' => $parent->id]);
        }

        $child = $this->actingAs($user)->withHeaders(self::HEADERS)
            ->postJson("/api/scopes/{$scope->id}/tasks", ['parent_id' => $parent->id, 'title' => 'Deep child'])
            ->assertCreated()->assertJsonPath('data.parent_id', $parent->id)->json('data');

        $this->assertDatabaseHas('tasks', ['id' => $child['id'], 'parent_id' => $parent->id]);
        $this->assertDatabaseHas('tasks', ['id' => $root->id, 'description' => 'Keep root description', 'result' => 'Keep root result', 'agent_notes' => 'Keep root notes']);
        $this->getJson("/api/scopes/{$scope->id}/tasks/{$parent->id}")
            ->assertOk()->assertJsonPath('data.children.0.id', $child['id']);
    }

    public function test_a_branch_with_children_can_be_moved_below_another_nested_task(): void
    {
        [$user, $scope] = $this->workspace();
        $attributes = ['scope_id' => $scope->id, 'created_by' => $user->id];
        $branch = Task::factory()->create($attributes);
        $child = Task::factory()->create([...$attributes, 'parent_id' => $branch->id]);
        $other = Task::factory()->create($attributes);
        $target = Task::factory()->create([...$attributes, 'parent_id' => $other->id]);

        $this->actingAs($user)->withHeaders(self::HEADERS)
            ->patchJson("/api/scopes/{$scope->id}/tasks/{$branch->id}", ['parent_id' => $target->id])
            ->assertOk()->assertJsonPath('data.parent_id', $target->id)->assertJsonPath('data.children.0.id', $child->id);

        $this->assertDatabaseHas('tasks', ['id' => $branch->id, 'parent_id' => $target->id]);
        $this->assertDatabaseHas('tasks', ['id' => $child->id, 'parent_id' => $branch->id]);
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $branch->id, 'action' => 'task.updated']);
    }

    public function test_assigning_a_deep_descendant_as_parent_returns_422_without_changing_the_task(): void
    {
        [$user, $scope] = $this->workspace();
        $attributes = ['scope_id' => $scope->id, 'created_by' => $user->id];
        $root = Task::factory()->create([...$attributes, 'title' => 'Original title']);
        $parent = $root;
        for ($level = 0; $level < 25; $level++) {
            $parent = Task::factory()->create([...$attributes, 'parent_id' => $parent->id]);
        }

        $this->actingAs($user)->withHeaders(self::HEADERS)
            ->patchJson("/api/scopes/{$scope->id}/tasks/{$root->id}", ['parent_id' => $parent->id, 'title' => 'Must not save'])
            ->assertUnprocessable()->assertJsonPath('message', 'A task cannot be its own ancestor.');

        $this->assertDatabaseHas('tasks', ['id' => $root->id, 'parent_id' => null, 'title' => 'Original title']);
        $this->assertDatabaseMissing('activity_logs', ['subject_id' => $root->id, 'action' => 'task.updated']);
    }

    public function test_assigning_a_task_to_itself_returns_422(): void
    {
        [$user, $scope] = $this->workspace();
        $task = Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $user->id]);

        $this->actingAs($user)->withHeaders(self::HEADERS)
            ->patchJson("/api/scopes/{$scope->id}/tasks/{$task->id}", ['parent_id' => $task->id])
            ->assertUnprocessable()->assertJsonPath('message', 'A task cannot be its own ancestor.');

        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'parent_id' => null]);
    }

    public function test_deleted_ancestors_do_not_allow_a_cycle_to_be_created(): void
    {
        [$user, $scope] = $this->workspace();
        $attributes = ['scope_id' => $scope->id, 'created_by' => $user->id];
        $root = Task::factory()->create($attributes);
        $middle = Task::factory()->create([...$attributes, 'parent_id' => $root->id]);
        $leaf = Task::factory()->create([...$attributes, 'parent_id' => $middle->id]);
        $middle->delete();

        $this->actingAs($user)->withHeaders(self::HEADERS)
            ->patchJson("/api/scopes/{$scope->id}/tasks/{$root->id}", ['parent_id' => $leaf->id])
            ->assertUnprocessable();

        $this->assertDatabaseHas('tasks', ['id' => $root->id, 'parent_id' => null]);
    }

    public function test_nested_tasks_cannot_be_attached_to_a_parent_from_another_scope(): void
    {
        [$user, $scope] = $this->workspace();
        $root = Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $user->id]);
        $child = Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $user->id, 'parent_id' => $root->id]);
        $otherScope = Scope::factory()->create(['owner_id' => $user->id]);
        $foreignParent = Task::factory()->create(['scope_id' => $otherScope->id, 'created_by' => $user->id]);

        $this->actingAs($user)->withHeaders(self::HEADERS)
            ->patchJson("/api/scopes/{$scope->id}/tasks/{$child->id}", ['parent_id' => $foreignParent->id])
            ->assertUnprocessable();

        $this->assertDatabaseHas('tasks', ['id' => $child->id, 'parent_id' => $root->id]);
    }

    public function test_checklist_conversion_works_inside_a_subtask(): void
    {
        [$user, $scope] = $this->workspace();
        $attributes = ['scope_id' => $scope->id, 'created_by' => $user->id];
        $root = Task::factory()->create($attributes);
        $parent = Task::factory()->create([...$attributes, 'parent_id' => $root->id]);
        $item = $this->actingAs($user)->withHeaders(self::HEADERS)
            ->postJson("/api/scopes/{$scope->id}/tasks/{$parent->id}/checklist", ['title' => 'Growing nested step'])
            ->assertCreated()->json('data');

        $child = $this->postJson("/api/scopes/{$scope->id}/tasks/{$parent->id}/checklist/{$item['id']}/convert-to-subtask")
            ->assertCreated()->assertJsonPath('data.parent_id', $parent->id)->json('data');

        $this->assertDatabaseHas('tasks', ['id' => $child['id'], 'parent_id' => $parent->id, 'title' => 'Growing nested step']);
        $this->assertDatabaseMissing('task_checklist_items', ['id' => $item['id']]);
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $child['id'], 'action' => 'checklist_item.converted_to_subtask']);
    }

    public function test_detaching_a_nested_branch_preserves_its_children(): void
    {
        [$user, $scope] = $this->workspace();
        $attributes = ['scope_id' => $scope->id, 'created_by' => $user->id];
        $root = Task::factory()->create($attributes);
        $branch = Task::factory()->create([...$attributes, 'parent_id' => $root->id]);
        $child = Task::factory()->create([...$attributes, 'parent_id' => $branch->id]);

        $this->actingAs($user)->withHeaders(self::HEADERS)
            ->postJson("/api/scopes/{$scope->id}/tasks/{$branch->id}/detach")
            ->assertOk()->assertJsonPath('data.parent_id', null);

        $this->assertDatabaseHas('tasks', ['id' => $branch->id, 'parent_id' => null]);
        $this->assertDatabaseHas('tasks', ['id' => $child->id, 'parent_id' => $branch->id]);
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
        $this->withHeaders(self::HEADERS)->getJson("/api/scopes/{$scope->id}/tasks")
            ->assertOk()->assertJsonPath('data.0.comments_count', 2);
        $this->withHeaders(self::HEADERS)->deleteJson("/api/scopes/{$scope->id}/tasks/{$task['id']}/comments/{$comment['id']}")
            ->assertNoContent();
        $this->assertSoftDeleted('comments', ['id' => $comment['id']]);
        $this->withHeaders(self::HEADERS)->getJson("/api/scopes/{$scope->id}/tasks/{$task['id']}/activity")
            ->assertOk()->assertJsonPath('data.0.action', 'task.comment_deleted')->assertJsonPath('data.0.actor.id', $user->id);
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
