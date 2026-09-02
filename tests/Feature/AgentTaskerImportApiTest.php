<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Scope;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentTaskerImportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_can_dry_run_and_idempotently_import_tasker_history(): void
    {
        [$agent, $scope, $token] = $this->agentWorkspace('all');
        $projectExternalId = strtolower((string) Str::ulid());
        $parentExternalId = strtolower((string) Str::ulid());
        $childExternalId = strtolower((string) Str::ulid());
        $checklistExternalId = strtolower((string) Str::ulid());
        $commentExternalId = strtolower((string) Str::ulid());
        $activityExternalId = strtolower((string) Str::ulid());
        $payload = [
            'source' => 'teftele-2026',
            'projects' => [[
                'external_id' => $projectExternalId,
                'title' => 'Legacy project',
                'key' => 'LEG',
                'description' => 'Imported description',
                'result' => null,
                'status' => 'active',
                'priority' => 4,
                'color' => '#123456',
                'started_on' => '2026-07-01',
                'due_on' => null,
                'completed_at' => null,
                'is_pinned' => true,
                'sort_order' => 7,
                'created_at' => '2026-07-01 10:00:00',
                'updated_at' => '2026-07-02 10:00:00',
            ]],
            'tasks' => [
                [
                    'external_id' => $parentExternalId,
                    'project_external_id' => $projectExternalId,
                    'parent_external_id' => null,
                    'assignee_id' => $agent->id,
                    'title' => 'Imported parent',
                    'description' => 'Legacy body',
                    'result' => 'Legacy result',
                    'status' => 'done',
                    'priority' => 5,
                    'due_at' => '2026-07-03 12:00:00',
                    'completed_at' => '2026-07-03 16:00:00',
                    'tracked_seconds' => 3600,
                    'is_pinned' => false,
                    'sort_order' => 10,
                    'legacy_spans' => [['kind' => 'fact', 'started_at' => '2026-07-03 15:00:00']],
                    'created_at' => '2026-07-01 10:00:00',
                    'updated_at' => '2026-07-03 16:00:00',
                ],
                [
                    'external_id' => $childExternalId,
                    'project_external_id' => $projectExternalId,
                    'parent_external_id' => $parentExternalId,
                    'assignee_id' => null,
                    'title' => 'Imported child',
                    'description' => null,
                    'result' => null,
                    'status' => 'todo',
                    'priority' => 2,
                    'due_at' => null,
                    'completed_at' => null,
                    'tracked_seconds' => 0,
                    'sort_order' => 20,
                    'created_at' => '2026-07-01 11:00:00',
                    'updated_at' => '2026-07-01 11:00:00',
                ],
            ],
            'checklist_items' => [[
                'external_id' => $checklistExternalId,
                'task_external_id' => $parentExternalId,
                'title' => 'Imported checklist item',
                'is_completed' => true,
                'completed_at' => '2026-07-03 15:30:00',
                'sort_order' => 1,
                'created_at' => '2026-07-02 10:00:00',
                'updated_at' => '2026-07-03 15:30:00',
            ]],
            'comments' => [[
                'external_id' => $commentExternalId,
                'task_external_id' => $parentExternalId,
                'kind' => 'report',
                'content' => 'Imported report',
                'created_at' => '2026-07-03 15:45:00',
                'updated_at' => '2026-07-03 15:45:00',
            ]],
            'activities' => [[
                'external_id' => $activityExternalId,
                'task_external_id' => $parentExternalId,
                'before' => ['status' => 'in_progress'],
                'after' => ['status' => 'done'],
                'created_at' => '2026-07-03 16:00:00',
            ]],
        ];

        $this->withToken($token)->postJson("/api/agent/scopes/{$scope->id}/imports/tasker", [...$payload, 'dry_run' => true])
            ->assertOk()
            ->assertJsonPath('data.dry_run', true)
            ->assertJsonPath('data.counts.projects.create', 1)
            ->assertJsonPath('data.counts.tasks.create', 2);
        $this->assertDatabaseCount('projects', 0);

        $this->withToken($token)->postJson("/api/agent/scopes/{$scope->id}/imports/tasker", $payload)
            ->assertCreated()
            ->assertJsonPath('data.counts.projects.created', 1)
            ->assertJsonPath('data.counts.tasks.created', 2)
            ->assertJsonPath('data.counts.checklist_items.created', 1)
            ->assertJsonPath('data.counts.comments.created', 1)
            ->assertJsonPath('data.counts.activities.created', 1);

        $this->assertDatabaseHas('projects', ['id' => $projectExternalId, 'scope_id' => $scope->id, 'key' => 'LEG']);
        $this->assertDatabaseHas('tasks', [
            'id' => $parentExternalId,
            'task_key' => 'LEG-1',
            'tracked_seconds' => 3600,
            'completed_at' => '2026-07-03 16:00:00',
        ]);
        $this->assertDatabaseHas('tasks', ['id' => $childExternalId, 'parent_id' => $parentExternalId, 'task_key' => 'LEG-2']);
        $this->assertDatabaseHas('task_checklist_items', ['id' => $checklistExternalId, 'completed_by' => $agent->id]);
        $this->assertDatabaseHas('comments', ['id' => $commentExternalId, 'commentable_id' => $parentExternalId]);
        $this->assertDatabaseHas('activity_logs', ['id' => $activityExternalId, 'subject_id' => $parentExternalId]);
        $this->assertSame('fact', Task::query()->findOrFail($parentExternalId)->meta['legacy_spans'][0]['kind']);

        $this->withToken($token)->postJson("/api/agent/scopes/{$scope->id}/imports/tasker", $payload)
            ->assertCreated()
            ->assertJsonPath('data.counts.projects.reused', 1)
            ->assertJsonPath('data.counts.tasks.reused', 2)
            ->assertJsonPath('data.counts.checklist_items.reused', 1)
            ->assertJsonPath('data.counts.comments.reused', 1)
            ->assertJsonPath('data.counts.activities.reused', 1);
        $this->assertDatabaseCount('tasks', 2);
    }

    public function test_restricted_agent_cannot_import_a_new_project(): void
    {
        [, $scope, $token] = $this->agentWorkspace('restricted');

        $this->withToken($token)->postJson("/api/agent/scopes/{$scope->id}/imports/tasker", [
            'source' => 'legacy',
            'dry_run' => true,
            'projects' => [[
                'external_id' => strtolower((string) Str::ulid()),
                'title' => 'Outside boundary',
                'key' => 'OUT',
                'status' => 'active',
                'priority' => 2,
                'color' => '#123456',
                'sort_order' => 1,
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ]],
        ])->assertForbidden();

        $this->withToken($token)->postJson("/api/agent/scopes/{$scope->id}/projects", [
            'title' => 'Outside boundary',
            'key' => 'OUT',
        ])->assertForbidden();
    }

    public function test_agent_cannot_fetch_update_or_open_checklist_for_hidden_project_task_by_id(): void
    {
        [$agent, $scope, $token, $owner] = $this->agentWorkspace('restricted');
        $allowed = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $hidden = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        ProjectMember::factory()->create(['project_id' => $allowed->id, 'user_id' => $agent->id, 'assigned_by' => $owner->id]);
        $allowedTask = Task::factory()->create(['scope_id' => $scope->id, 'project_id' => $allowed->id, 'created_by' => $owner->id]);
        $hiddenTask = Task::factory()->create(['scope_id' => $scope->id, 'project_id' => $hidden->id, 'created_by' => $owner->id]);

        $this->withToken($token)->getJson("/api/agent/scopes/{$scope->id}/tasks/{$allowedTask->id}")->assertOk();
        $this->withToken($token)->getJson("/api/agent/scopes/{$scope->id}/tasks/{$hiddenTask->id}")->assertForbidden();
        $this->withToken($token)->patchJson("/api/agent/scopes/{$scope->id}/tasks/{$hiddenTask->id}", ['title' => 'Leaked'])->assertForbidden();
        $this->withToken($token)->getJson("/api/agent/scopes/{$scope->id}/tasks/{$hiddenTask->id}/checklist")->assertForbidden();
    }

    /** @return array{User, Scope, string, User} */
    private function agentWorkspace(string $projectAccessMode): array
    {
        $owner = User::factory()->create();
        $agent = User::factory()->agent()->create(['status' => 'active', 'is_active' => true]);
        $scope = Scope::factory()->create(['owner_id' => $owner->id]);
        $scope->members()->create([
            'user_id' => $agent->id,
            'role' => 'member',
            'permissions' => ['allow' => ['task.view', 'task.create', 'task.update'], 'deny' => []],
            'project_access_mode' => $projectAccessMode,
            'book_access_mode' => 'none',
            'joined_at' => now(),
        ]);
        $token = $agent->createToken('Importer', ['task.view', 'task.create', 'task.update'])->plainTextToken;

        return [$agent, $scope, $token, $owner];
    }
}
