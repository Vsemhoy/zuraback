<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Scope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentFactorApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_can_create_fact_and_link_it_only_to_accessible_project(): void
    {
        $owner = User::factory()->create();
        $agent = User::factory()->create(['type' => 'agent', 'status' => 'active', 'is_active' => true]);
        $scope = Scope::factory()->create(['owner_id' => $owner->id]);
        $scope->members()->create([
            'user_id' => $agent->id,
            'role' => 'member',
            'permissions' => ['allow' => ['task.view', 'task.create', 'task.update'], 'deny' => []],
            'project_access_mode' => 'restricted',
            'book_access_mode' => 'none',
            'joined_at' => now(),
        ]);
        $allowed = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $hidden = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        ProjectMember::factory()->create([
            'project_id' => $allowed->id,
            'user_id' => $agent->id,
            'assigned_by' => $owner->id,
            'permissions' => ['allow' => ['task.view'], 'deny' => []],
            'is_active' => true,
        ]);
        $token = $agent->createToken('test', ['task.view', 'task.create', 'task.update'])->plainTextToken;

        $fact = $this->withToken($token)->postJson("/api/agent/scopes/{$scope->id}/facts", [
            'label' => 'Frontend repository',
            'value' => 'https://github.com/example/frontend',
            'format' => 'text',
            'kind' => 'configuration',
        ])->assertCreated()->json('data');

        $this->withToken($token)->postJson("/api/agent/scopes/{$scope->id}/links", [
            'source_type' => 'fact',
            'source_id' => $fact['id'],
            'target_type' => 'project',
            'target_id' => $allowed->id,
            'relation' => 'documents',
        ])->assertCreated();

        $this->withToken($token)->postJson("/api/agent/scopes/{$scope->id}/links", [
            'source_type' => 'fact',
            'source_id' => $fact['id'],
            'target_type' => 'project',
            'target_id' => $hidden->id,
            'relation' => 'documents',
        ])->assertForbidden();
    }

    public function test_agent_factor_creation_requires_task_create_ability(): void
    {
        $owner = User::factory()->create();
        $agent = User::factory()->create(['type' => 'agent', 'status' => 'active', 'is_active' => true]);
        $scope = Scope::factory()->create(['owner_id' => $owner->id]);
        $scope->members()->create([
            'user_id' => $agent->id,
            'role' => 'observer',
            'project_access_mode' => 'all',
            'book_access_mode' => 'none',
            'joined_at' => now(),
        ]);
        $token = $agent->createToken('read only', ['task.view'])->plainTextToken;

        $this->withToken($token)->postJson("/api/agent/scopes/{$scope->id}/facts", [
            'label' => 'Forbidden',
            'value' => 'nope',
        ])->assertForbidden();
    }
}
