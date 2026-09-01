<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Scope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentTaskDelegationTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-App-Request' => 'Zuratax'];

    public function test_human_assignee_can_delegate_a_task_to_an_agent_and_agent_can_fetch_personal_work(): void
    {
        [$owner, $scope, $project] = $this->workspace();
        $agent = User::factory()->agent()->create(['name' => 'Codex Agent']);
        $scope->members()->create([
            'user_id' => $agent->id,
            'role' => 'observer',
            'permissions' => ['allow' => ['task.view'], 'deny' => []],
            'project_access_mode' => 'restricted',
            'joined_at' => now(),
        ]);
        ProjectMember::query()->create([
            'project_id' => $project->id,
            'user_id' => $agent->id,
            'assigned_by' => $owner->id,
            'permissions' => ['allow' => [], 'deny' => []],
        ]);

        $task = $this->actingAs($owner)->withHeaders(self::HEADERS)
            ->postJson("/api/scopes/{$scope->id}/tasks", [
                'project_id' => $project->id,
                'title' => 'Prepare monthly report',
                'assignee_id' => $owner->id,
                'is_agent_delegatable' => true,
                'delegated_agent_id' => $agent->id,
            ])->assertCreated()
            ->assertJsonPath('data.assignee.id', $owner->id)
            ->assertJsonPath('data.delegated_agent.id', $agent->id)
            ->assertJsonPath('data.is_agent_delegatable', true)
            ->json('data');

        $closedTask = $scope->tasks()->create([
            'project_id' => $project->id,
            'created_by' => $owner->id,
            'assignee_id' => $owner->id,
            'is_agent_delegatable' => true,
            'delegated_agent_id' => $agent->id,
            'task_key' => 'ADM-99',
            'number' => 99,
            'title' => 'Closed delegated task',
            'status' => 'done',
        ]);
        $token = $agent->createToken('Agent test', ['task.view'])->plainTextToken;
        auth()->guard('web')->logout();

        $this->withToken($token)->getJson('/api/agent/tasks')
            ->assertOk()
            ->assertJsonFragment(['id' => $task['id']])
            ->assertJsonMissing(['id' => $closedTask->id])
            ->assertJsonPath('data.0.scope.id', $scope->id);
        $this->withToken($token)->getJson('/api/agent/tasks?include_closed=1')
            ->assertOk()->assertJsonFragment(['id' => $closedTask->id]);

        $this->actingAs($owner)->withHeaders(self::HEADERS)
            ->patchJson("/api/scopes/{$scope->id}/tasks/{$task['id']}", ['is_agent_delegatable' => false])
            ->assertOk()->assertJsonPath('data.delegated_agent_id', null);
        $this->assertDatabaseHas('tasks', ['id' => $task['id'], 'is_agent_delegatable' => false, 'delegated_agent_id' => null]);
    }

    public function test_task_rejects_agent_without_access_to_its_project(): void
    {
        [$owner, $scope, $project] = $this->workspace();
        $agent = User::factory()->agent()->create();
        $scope->members()->create([
            'user_id' => $agent->id,
            'role' => 'observer',
            'permissions' => ['allow' => ['task.view'], 'deny' => []],
            'project_access_mode' => 'none',
            'joined_at' => now(),
        ]);

        $this->actingAs($owner)->withHeaders(self::HEADERS)
            ->postJson("/api/scopes/{$scope->id}/tasks", [
                'project_id' => $project->id,
                'title' => 'Forbidden delegation',
                'assignee_id' => $owner->id,
                'delegated_agent_id' => $agent->id,
            ])->assertUnprocessable();
    }

    /** @return array{User, Scope, Project} */
    private function workspace(): array
    {
        $owner = User::factory()->create();
        $scope = Scope::query()->create(['owner_id' => $owner->id, 'name' => 'Work', 'slug' => 'work']);
        $scope->members()->create(['user_id' => $owner->id, 'role' => 'owner', 'joined_at' => now()]);
        $project = $scope->projects()->create(['created_by' => $owner->id, 'title' => 'Administration', 'key' => 'ADM']);

        return [$owner, $scope, $project];
    }
}
