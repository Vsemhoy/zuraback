<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Scope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractorApiTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-App-Request' => 'Zuratax'];

    public function test_owner_can_create_a_restricted_virtual_contractor_and_work_as_them_with_audited_operator(): void
    {
        [$owner, $scope] = $this->workspace();
        $allowedProject = $this->project($scope, $owner, 'ADM');
        $hiddenProject = $this->project($scope, $owner, 'SKD');

        $virtual = $this->actingAs($owner)->withHeaders(self::HEADERS)
            ->postJson("/api/scopes/{$scope->id}/contractors", [
                'name' => 'Elena Virtual', 'type' => 'virtual', 'role' => 'member',
                'project_access_mode' => 'restricted', 'project_ids' => [$allowedProject->id],
                'permissions' => ['allow' => ['task.view', 'task.create', 'task.update'], 'deny' => []],
                'can_act_as' => true,
            ])->assertCreated()->assertJsonPath('data.type', 'virtual')
            ->assertJsonPath('data.projects.0.id', $allowedProject->id)
            ->assertJsonPath('data.can_act_as', true)->json('data');

        $this->withHeaders(self::HEADERS)
            ->postJson("/api/scopes/{$scope->id}/contractors/{$virtual['id']}/act")
            ->assertOk()->assertJsonPath('data.acting_as.id', $virtual['id']);
        $this->withHeaders(self::HEADERS)->getJson('/api/auth/me')
            ->assertOk()->assertJsonPath('data.acting_as.id', $virtual['id']);

        $task = $this->withHeaders(self::HEADERS)
            ->postJson("/api/scopes/{$scope->id}/tasks", ['project_id' => $allowedProject->id, 'title' => 'Work on behalf'])
            ->assertCreated()->json('data');
        $this->assertDatabaseHas('tasks', ['id' => $task['id'], 'created_by' => $virtual['id']]);

        $this->withHeaders(self::HEADERS)
            ->postJson("/api/scopes/{$scope->id}/tasks", ['project_id' => $hiddenProject->id, 'title' => 'Outside boundary'])
            ->assertForbidden();
        $this->withHeaders(self::HEADERS)
            ->postJson("/api/scopes/{$scope->id}/tasks/{$task['id']}/comments", ['content' => 'Done as Elena'])
            ->assertCreated()->assertJsonPath('data.created_by.id', $virtual['id']);

        $this->assertDatabaseHas('activity_logs', ['actor_id' => $virtual['id'], 'action' => 'task.comment_created']);
        $this->assertDatabaseHas('activity_logs', ['context->performed_by' => $owner->id]);

        $this->withHeaders(self::HEADERS)->deleteJson('/api/contractors/acting')->assertNoContent();
        $this->withHeaders(self::HEADERS)->getJson('/api/auth/me')->assertJsonPath('data.acting_as', null);
    }

    public function test_agent_token_is_revocable_and_respects_scope_project_and_ability_boundaries(): void
    {
        [$owner, $scope] = $this->workspace();
        $allowedProject = $this->project($scope, $owner, 'API');
        $hiddenProject = $this->project($scope, $owner, 'SEC');
        $hiddenTask = $scope->tasks()->create([
            'project_id' => $hiddenProject->id, 'created_by' => $owner->id,
            'task_key' => 'SEC-1', 'number' => 1, 'title' => 'Hidden task',
        ]);

        $agent = $this->actingAs($owner)->withHeaders(self::HEADERS)
            ->postJson("/api/scopes/{$scope->id}/contractors", [
                'name' => 'Zuratax Copilot', 'type' => 'agent', 'role' => 'observer',
                'project_access_mode' => 'restricted', 'project_ids' => [$allowedProject->id],
                'permissions' => ['allow' => ['task.view', 'task.create'], 'deny' => ['task.update']],
            ])->assertCreated()->json('data');
        $issued = $this->withHeaders(self::HEADERS)
            ->postJson("/api/scopes/{$scope->id}/contractors/{$agent['id']}/tokens", [
                'name' => 'Codex workstation', 'abilities' => ['task.view', 'task.create'],
            ])->assertCreated()->json('data');

        auth()->guard('web')->logout();

        $created = $this->withToken($issued['token'])
            ->postJson("/api/agent/scopes/{$scope->id}/tasks", ['project_id' => $allowedProject->id, 'title' => 'Created by agent'])
            ->assertCreated()->json('data');
        $this->assertDatabaseHas('tasks', ['id' => $created['id'], 'created_by' => $agent['id']]);
        $this->withToken($issued['token'])->getJson("/api/agent/scopes/{$scope->id}/tasks")
            ->assertOk()->assertJsonMissing(['id' => $hiddenTask->id])->assertJsonFragment(['id' => $created['id']]);
        $this->withToken($issued['token'])
            ->patchJson("/api/agent/scopes/{$scope->id}/tasks/{$created['id']}", ['title' => 'Forbidden update'])
            ->assertForbidden();

        $this->actingAs($owner)->withHeaders(self::HEADERS)
            ->deleteJson("/api/scopes/{$scope->id}/contractors/{$agent['id']}/tokens/{$issued['id']}")
            ->assertNoContent();
        auth()->guard('web')->logout();
        auth()->forgetGuards();
        $this->withToken($issued['token'])->getJson('/api/agent/me')->assertUnauthorized();
    }

    public function test_virtual_and_agent_accounts_cannot_use_the_web_login(): void
    {
        $virtual = User::factory()->virtual()->create(['email' => 'virtual@example.test', 'password' => 'secret-password']);
        $agent = User::factory()->agent()->create(['email' => 'agent@example.test', 'password' => 'secret-password']);

        $this->withHeaders(self::HEADERS)->postJson('/api/auth/login', ['identity' => $virtual->email, 'password' => 'secret-password'])->assertUnprocessable();
        $this->withHeaders(self::HEADERS)->postJson('/api/auth/login', ['identity' => $agent->email, 'password' => 'secret-password'])->assertUnprocessable();
    }

    public function test_owner_access_can_be_saved_and_reserved_routes_do_not_bind_as_contractors(): void
    {
        [$owner, $scope] = $this->workspace();

        $this->actingAs($owner)->withHeaders(self::HEADERS)
            ->getJson("/api/scopes/{$scope->id}/contractors/options")
            ->assertOk()
            ->assertJsonFragment(['contractor.manage']);

        $this->withHeaders(self::HEADERS)
            ->putJson("/api/scopes/{$scope->id}/contractors/{$owner->id}/access", [
                'role' => 'owner',
                'project_access_mode' => 'all',
                'project_ids' => [],
                'permissions' => ['allow' => ['*'], 'deny' => []],
                'can_act_as' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.role', 'owner')
            ->assertJsonPath('data.permissions.allow.0', '*');

        $this->assertDatabaseHas('scope_members', [
            'scope_id' => $scope->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);
    }

    /** @return array{User, Scope} */
    private function workspace(): array
    {
        $owner = User::factory()->create();
        $scope = Scope::query()->create(['owner_id' => $owner->id, 'name' => 'Work', 'slug' => 'work']);
        $scope->members()->create(['user_id' => $owner->id, 'role' => 'owner', 'joined_at' => now()]);

        return [$owner, $scope];
    }

    private function project(Scope $scope, User $owner, string $key): Project
    {
        return $scope->projects()->create(['created_by' => $owner->id, 'title' => "{$key} project", 'key' => $key]);
    }
}
