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
                'preferred_language' => 'zh',
                'project_access_mode' => 'restricted', 'project_ids' => [$allowedProject->id],
                'permissions' => ['allow' => ['task.view', 'task.create', 'task.update'], 'deny' => []],
                'can_act_as' => true,
            ])->assertCreated()->assertJsonPath('data.type', 'virtual')
            ->assertJsonPath('data.preferred_language', 'zh')
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

    public function test_agent_can_fetch_the_current_markdown_specification_without_exposing_its_token(): void
    {
        $this->get('/api/agent/spec', ['Accept' => 'text/markdown'])->assertUnauthorized();

        [$owner, $scope] = $this->workspace();
        $agent = User::factory()->agent()->create(['name' => 'Instruction Agent', 'preferred_language' => 'ru']);
        $scope->members()->create([
            'user_id' => $agent->id,
            'role' => 'observer',
            'permissions' => ['allow' => ['task.view'], 'deny' => []],
            'project_access_mode' => 'none',
            'joined_at' => now(),
        ]);
        $token = $agent->createToken('Instruction test', ['task.view'])->plainTextToken;

        $response = $this->withToken($token)->get('/api/agent/spec');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/markdown; charset=UTF-8')
            ->assertSee('# Zuratax Agent API', false)
            ->assertSee('Specification version: 2026-09-04.2', false)
            ->assertSee('/api/agent/scopes/{scope}/lore/context', false)
            ->assertSee('Working language: **Russian** (`ru`)', false)
            ->assertSee('answers in Russian', false)
            ->assertSee('/api/agent/scopes/{scope}/books', false)
            ->assertSee('/api/agent/tasks', false)
            ->assertSee('agent_notes', false)
            ->assertSee('durable AI findings', false)
            ->assertSee($scope->id, false)
            ->assertDontSee($token, false);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_agent_can_use_booker_routes_only_with_explicit_book_access(): void
    {
        [$owner, $scope] = $this->workspace();
        $agent = User::factory()->agent()->create(['created_by' => $owner->id]);
        $scope->members()->create([
            'user_id' => $agent->id,
            'role' => 'observer',
            'permissions' => ['allow' => ['book.view', 'book.create', 'book.update', 'book.delete'], 'deny' => []],
            'book_access_mode' => 'all',
            'joined_at' => now(),
        ]);
        $token = $agent->createToken('Booker client', ['book.view', 'book.create', 'book.update', 'book.delete'])->plainTextToken;

        $book = $this->withToken($token)->postJson("/api/agent/scopes/{$scope->id}/books", ['title' => 'Agent handbook'])
            ->assertCreated()->assertJsonPath('data.title', 'Agent handbook')->json('data');
        $this->withToken($token)->getJson("/api/agent/scopes/{$scope->id}/books")
            ->assertOk()->assertJsonPath('data.0.id', $book['id']);
        $this->withToken($token)->deleteJson("/api/agent/scopes/{$scope->id}/books/{$book['id']}")->assertNoContent();
        $this->assertSoftDeleted('books', ['id' => $book['id']]);
    }

    public function test_virtual_and_agent_accounts_cannot_use_the_web_login(): void
    {
        $virtual = User::factory()->virtual()->create(['email' => 'virtual@example.test', 'password' => 'secret-password']);
        $agent = User::factory()->agent()->create(['email' => 'agent@example.test', 'password' => 'secret-password']);

        $this->withHeaders(self::HEADERS)->postJson('/api/auth/login', ['identity' => $virtual->email, 'password' => 'secret-password'])->assertUnprocessable();
        $this->withHeaders(self::HEADERS)->postJson('/api/auth/login', ['identity' => $agent->email, 'password' => 'secret-password'])->assertUnprocessable();
    }

    public function test_virtual_contractor_can_be_activated_as_a_real_user_with_a_password(): void
    {
        [$owner, $scope] = $this->workspace();
        $virtual = User::factory()->virtual()->create(['created_by' => $owner->id]);
        $scope->members()->create(['user_id' => $virtual->id, 'role' => 'member', 'joined_at' => now()]);

        $this->actingAs($owner)->withHeaders(self::HEADERS)
            ->patchJson("/api/scopes/{$scope->id}/contractors/{$virtual->id}", [
                'type' => 'real',
                'email' => 'activated@example.test',
                'password' => 'new-secret-password',
            ])->assertOk()
            ->assertJsonPath('data.type', 'real')
            ->assertJsonPath('data.email', 'activated@example.test');

        auth()->guard('web')->logout();
        auth()->forgetGuards();

        $this->withHeaders(self::HEADERS)->postJson('/api/auth/login', [
            'identity' => 'activated@example.test',
            'password' => 'new-secret-password',
        ])->assertOk()->assertJsonPath('data.id', $virtual->id);
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

    public function test_contractor_has_a_position_and_can_be_added_to_another_manageable_scope(): void
    {
        [$owner, $scope] = $this->workspace();
        $second = Scope::query()->create(['owner_id' => $owner->id, 'name' => 'Personal', 'slug' => 'personal']);
        $second->members()->create(['user_id' => $owner->id, 'role' => 'owner', 'joined_at' => now()]);

        $contractor = $this->actingAs($owner)->withHeaders(self::HEADERS)
            ->postJson("/api/scopes/{$scope->id}/contractors", [
                'name' => 'Sergey Admin', 'position' => 'System administrator', 'type' => 'virtual',
            ])->assertCreated()->assertJsonPath('data.position', 'System administrator')->json('data');

        $this->withHeaders(self::HEADERS)
            ->postJson("/api/scopes/{$scope->id}/contractors/{$contractor['id']}/scopes", ['scope_ids' => [$second->id]])
            ->assertOk()->assertJsonFragment(['id' => $second->id, 'name' => 'Personal', 'role' => 'member']);

        $this->assertDatabaseHas('scope_members', ['scope_id' => $second->id, 'user_id' => $contractor['id'], 'role' => 'member']);
    }

    public function test_only_executor_people_are_assignable_while_all_people_remain_available_as_customers(): void
    {
        [$owner, $scope] = $this->workspace();
        $manager = $this->actingAs($owner)->withHeaders(self::HEADERS)
            ->postJson("/api/scopes/{$scope->id}/contractors", [
                'name' => 'Non executing manager', 'type' => 'virtual', 'is_executor' => false,
            ])->assertCreated()->assertJsonPath('data.is_executor', false)->json('data');

        $this->withHeaders(self::HEADERS)->getJson("/api/scopes/{$scope->id}/contractors/assignable")
            ->assertOk()
            ->assertJsonPath('data.people', fn (array $people): bool => collect($people)->contains('id', $manager['id']))
            ->assertJsonPath('data.assignees', fn (array $people): bool => ! collect($people)->contains('id', $manager['id']));

        $this->withHeaders(self::HEADERS)
            ->patchJson("/api/scopes/{$scope->id}/contractors/{$manager['id']}", ['is_executor' => true])
            ->assertOk()->assertJsonPath('data.is_executor', true);

        $this->withHeaders(self::HEADERS)->getJson("/api/scopes/{$scope->id}/contractors/assignable")
            ->assertOk()->assertJsonPath('data.assignees', fn (array $people): bool => collect($people)->contains('id', $manager['id']));
    }

    public function test_member_can_manage_only_agents_they_created(): void
    {
        [$owner, $scope] = $this->workspace();
        $member = User::factory()->create();
        $scope->members()->create([
            'user_id' => $member->id,
            'role' => 'member',
            'permissions' => ['allow' => ['task.view', 'task.create', 'task.update', 'book.view'], 'deny' => []],
            'project_access_mode' => 'all',
            'book_access_mode' => 'projects',
            'joined_at' => now(),
        ]);
        $ownerAgent = User::factory()->agent()->create(['created_by' => $owner->id]);
        $scope->members()->create(['user_id' => $ownerAgent->id, 'role' => 'observer', 'joined_at' => now()]);

        $agent = $this->actingAs($member)->withHeaders(self::HEADERS)
            ->postJson("/api/scopes/{$scope->id}/contractors", [
                'name' => 'Andrew Agent',
                'type' => 'agent',
                'role' => 'admin',
                'project_access_mode' => 'all',
                'book_access_mode' => 'all',
                'permissions' => ['allow' => ['task.view', 'task.create', 'book.view'], 'deny' => []],
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'agent')
            ->assertJsonPath('data.role', 'observer')
            ->assertJsonPath('data.book_access_mode', 'projects')
            ->json('data');

        $this->withHeaders(self::HEADERS)->getJson("/api/scopes/{$scope->id}/contractors")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $agent['id']);
        $this->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/contractors", [
            'name' => 'Forbidden virtual', 'type' => 'virtual',
        ])->assertForbidden();
        $this->withHeaders(self::HEADERS)->patchJson("/api/scopes/{$scope->id}/contractors/{$ownerAgent->id}", [
            'name' => 'Stolen agent',
        ])->assertForbidden();
        $this->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/contractors/{$agent['id']}/tokens", [
            'name' => 'Andrew workstation', 'abilities' => ['task.view'],
        ])->assertCreated()->assertJsonStructure(['data' => ['token']]);
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
