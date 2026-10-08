<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Project;
use App\Models\Scope;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepartmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-App-Request' => 'Zuratax'];

    public function test_cross_department_queue_claim_and_personal_updates_do_not_open_the_project(): void
    {
        $owner = User::factory()->create();
        $sales = User::factory()->create(['is_executor' => true]);
        $engineer = User::factory()->create(['is_executor' => true]);
        $stranger = User::factory()->create();
        $scope = Scope::factory()->create(['owner_id' => $owner->id]);
        $department = Department::factory()->create(['scope_id' => $scope->id]);
        foreach ([$sales, $engineer, $stranger] as $user) {
            $scope->members()->create(['user_id' => $user->id, 'role' => 'member', 'project_access_mode' => 'restricted', 'department_id' => $user->id === $engineer->id ? $department->id : null]);
        }
        $base = "/api/scopes/{$scope->id}";
        $this->actingAs($sales)->withHeaders(self::HEADERS);
        $taskId = $this->postJson($base.'/tasks', ['title' => 'Request to engineers', 'department_id' => $department->id])->assertCreated()->assertJsonPath('data.assignee_id', null)->json('data.id');
        $this->getJson($base.'/dashboard')->assertOk()->assertJsonPath('data.created_by_me.0.id', $taskId);
        $this->actingAs($engineer)->getJson($base.'/dashboard')->assertOk()->assertJsonPath('data.department_queue.0.id', $taskId);
        $this->postJson($base.'/tasks/'.$taskId.'/claim')->assertOk()->assertJsonPath('data.assignee_id', $engineer->id);
        $this->postJson($base.'/tasks/'.$taskId.'/claim')->assertConflict();
        $this->patchJson($base.'/tasks/'.$taskId, ['status' => 'done'])->assertOk();
        $this->postJson($base.'/tasks/'.$taskId.'/comments', ['content' => 'Ready'])->assertCreated();
        $this->actingAs($sales)->getJson($base.'/dashboard')->assertOk()->assertJsonPath('data.recently_completed.0.id', $taskId)->assertJsonFragment(['message' => 'обновил комментарии']);
        $project = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'private']);
        Task::findOrFail($taskId)->update(['project_id' => $project->id]);
        $hidden = Task::factory()->create(['scope_id' => $scope->id, 'project_id' => $project->id, 'parent_id' => $taskId, 'created_by' => $owner->id]);
        $this->getJson($base.'/tasks/'.$taskId)->assertOk()->assertJsonCount(0, 'data.children');
        $this->getJson($base.'/tasks')->assertOk()->assertJsonFragment(['id' => $taskId])->assertJsonMissing(['id' => $hidden->id]);
        $this->getJson($base.'/projects/'.$project->id)->assertForbidden();
        $this->actingAs($stranger)->getJson($base.'/tasks/'.$taskId)->assertForbidden();
        $this->getJson($base.'/tasks')->assertOk()->assertJsonMissing(['id' => $taskId]);
        $this->getJson($base.'/dashboard')->assertOk()->assertJsonCount(0, 'data.task_updates');
    }

    public function test_departments_are_scoped_managed_and_protect_nonempty_deletion(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $scope = Scope::factory()->create(['owner_id' => $owner->id]);
        $scope->members()->create(['user_id' => $member->id, 'role' => 'member']);
        $base = "/api/scopes/{$scope->id}";
        $this->actingAs($owner)->withHeaders(self::HEADERS);
        $id = $this->postJson($base.'/departments', ['name' => 'IT', 'user_ids' => [$member->id]])->assertOk()->json('data.id');
        $this->assertDatabaseHas('scope_members', ['user_id' => $member->id, 'department_id' => $id]);
        $this->deleteJson($base.'/departments/'.$id)->assertUnprocessable();
        $foreign = Department::factory()->create();
        $this->putJson($base.'/departments/'.$foreign->id, ['name' => 'No'])->assertNotFound();
        $this->postJson($base.'/projects', ['key' => 'DEP', 'title' => 'Department project', 'department_id' => $id, 'department_ids' => [$id]])->assertCreated()->assertJsonPath('data.department.id', $id)->assertJsonCount(1, 'data.departments');
        $this->postJson($base.'/tasks', ['title' => 'Foreign department', 'department_id' => $foreign->id])->assertUnprocessable();
        $this->actingAs($member)->putJson($base.'/departments/'.$id, ['name' => 'No'])->assertForbidden();
        $this->postJson($base.'/departments', ['name' => 'No'])->assertForbidden();
    }

    public function test_project_department_members_can_work_on_public_projects_and_inherit_task_routing(): void
    {
        $owner = User::factory()->create();
        $worker = User::factory()->create(['is_executor' => true]);
        $scope = Scope::factory()->create(['owner_id' => $owner->id]);
        $department = Department::factory()->create(['scope_id' => $scope->id]);
        $partner = Department::factory()->create(['scope_id' => $scope->id]);
        $scope->members()->create(['user_id' => $worker->id, 'role' => 'member', 'project_access_mode' => 'restricted', 'department_id' => $department->id]);
        $project = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'department_id' => $partner->id, 'visibility' => 'scope']);
        $project->departments()->attach($department);
        $private = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'department_id' => $department->id, 'visibility' => 'private']);
        $base = "/api/scopes/{$scope->id}";
        $this->actingAs($worker)->withHeaders(self::HEADERS)->getJson($base.'/projects')->assertOk()->assertJsonFragment(['id' => $project->id])->assertJsonMissing(['id' => $private->id]);
        $this->getJson($base.'/projects/'.$project->id)->assertOk();
        $this->postJson($base.'/tasks', ['project_id' => $project->id, 'title' => 'Partner request', 'assignee_id' => null])->assertCreated()->assertJsonPath('data.department_id', $partner->id)->assertJsonPath('data.assignee_id', null);
        $this->postJson($base.'/tasks', ['project_id' => $private->id, 'department_id' => $department->id, 'title' => 'No entry'])->assertForbidden();
    }
}
