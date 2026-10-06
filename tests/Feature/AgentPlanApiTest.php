<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\PlanItem;
use App\Models\Project;
use App\Models\Scope;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AgentPlanApiTest extends TestCase
{
    use RefreshDatabase;

    private function agent(array $abilities = ['report.view', 'task.view', 'report.write', 'task.update']): array
    {
        $agent = User::factory()->create(['type' => 'agent', 'status' => 'active', 'is_active' => true]);
        $scope = Scope::factory()->create();
        $scope->members()->create(['user_id' => $agent->id, 'role' => 'admin', 'is_active' => true, 'project_access_mode' => 'all']);
        $this->withToken($agent->createToken('Planner test', $abilities)->plainTextToken);

        return [$agent, $scope, "/api/agent/scopes/{$scope->id}/plans"];
    }

    public function test_agent_can_manage_plans_and_schedule_linked_tasks(): void
    {
        [$agent, $scope, $url] = $this->agent();
        $task = Task::factory()->create(['scope_id' => $scope->id, 'project_id' => null, 'status' => 'todo']);
        $this->getJson($url.'/options')->assertOk()->assertJsonStructure(['data' => ['projects', 'people']]);
        $id = $this->postJson($url, ['title' => 'Monthly work', 'month' => '2026-10', 'task_ids' => [$task->id], 'task_dates' => [$task->id => '2026-10-15']])
            ->assertOk()->assertJsonPath('data.tasks_count', 1)->json('data.id');
        $this->getJson($url.'/candidates')->assertOk()->assertJsonPath('data.0.linked_plans.0.id', $id);
        $this->patchJson("{$url}/{$id}", ['actual_result' => 'Verified', 'completed' => true])->assertOk()
            ->assertJsonPath('data.title', 'Monthly work')->assertJsonPath('data.completed_by', $agent->id)
            ->assertJsonPath('data.tasks_count', 1);
        $this->getJson($url.'?year=2026&month=2026-10&status=done')->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.done', 1);
        $this->getJson("{$url}/{$id}")->assertOk()->assertJsonPath('data.actual_result', 'Verified');
        $this->assertDatabaseHas('plan_item_task', ['plan_item_id' => $id, 'task_id' => $task->id]);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'todo', 'due_at' => '2026-10-15 12:00:00']);
        $this->assertDatabaseHas('activity_logs', ['actor_id' => $agent->id, 'subject_id' => $id, 'action' => 'plan.updated']);
        $audit = ActivityLog::query()->where('actor_id', $agent->id)->where('action', 'agent.api.patch')->firstOrFail();
        $this->assertSame("{$url}/{$id}", $audit->context['path']);
        $this->assertSame(200, $audit->after['status']);
        $this->patchJson("{$url}/{$id}", ['completed' => false, 'task_ids' => []])->assertOk()
            ->assertJsonPath('data.completed_at', null)->assertJsonPath('data.tasks_count', 0);
        $this->assertDatabaseMissing('plan_item_task', ['plan_item_id' => $id]);
        $this->deleteJson("{$url}/{$id}")->assertNoContent();
        $this->assertSoftDeleted('plan_items', ['id' => $id]);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'deleted_at' => null]);
    }

    public static function missingWriteAbilities(): array
    {
        return [
            'read only' => [['report.view', 'task.view']],
            'no task update' => [['report.view', 'task.view', 'report.write']],
            'no report write' => [['report.view', 'task.view', 'task.update']],
        ];
    }

    #[DataProvider('missingWriteAbilities')]
    public function test_insufficient_token_cannot_write_plans_403(array $abilities): void
    {
        [, $scope, $url] = $this->agent($abilities);
        $plan = PlanItem::factory()->create(['scope_id' => $scope->id, 'project_id' => null]);
        $this->getJson($url.'?year=2026')->assertOk();
        $this->postJson($url, ['title' => 'Forbidden', 'month' => '2026-10'])->assertForbidden();
        $this->patchJson("{$url}/{$plan->id}", ['title' => 'Forbidden'])->assertForbidden();
        $this->deleteJson("{$url}/{$plan->id}")->assertForbidden();
        $this->assertDatabaseMissing('plan_items', ['title' => 'Forbidden']);
        $this->assertDatabaseHas('plan_items', ['id' => $plan->id, 'deleted_at' => null]);
    }

    public function test_membership_denial_overrides_full_token_403(): void
    {
        [$agent, $scope, $url] = $this->agent();
        $scope->members()->where('user_id', $agent->id)->update(['permissions' => ['deny' => ['report.write']]]);
        $this->postJson($url, ['title' => 'Forbidden', 'month' => '2026-10'])->assertForbidden();
        $this->assertDatabaseCount('plan_items', 0);
    }

    public function test_plans_require_report_read_permission_403(): void
    {
        [, , $url] = $this->agent(['task.view']);
        $this->getJson($url.'?year=2026')->assertForbidden();
        $this->getJson($url.'/options')->assertForbidden();
        $this->getJson($url.'/candidates')->assertForbidden();
    }

    public function test_agent_cannot_read_or_modify_private_and_foreign_plans_404(): void
    {
        [, $scope, $url] = $this->agent();
        $project = Project::factory()->create(['scope_id' => $scope->id, 'visibility' => 'private']);
        $private = PlanItem::factory()->create(['scope_id' => $scope->id, 'project_id' => $project->id, 'month' => '2026-10']);
        $foreign = PlanItem::factory()->create(['project_id' => null, 'month' => '2026-10']);
        $this->getJson($url.'?year=2026')->assertOk()->assertJsonCount(0, 'data.items');
        $this->getJson($url.'/options')->assertOk()->assertJsonCount(0, 'data.projects');
        foreach ([$private, $foreign] as $plan) {
            $this->getJson("{$url}/{$plan->id}")->assertNotFound();
            $this->patchJson("{$url}/{$plan->id}", ['completed' => true])->assertNotFound();
            $this->deleteJson("{$url}/{$plan->id}")->assertNotFound();
            $this->assertDatabaseHas('plan_items', ['id' => $plan->id, 'completed_at' => null, 'deleted_at' => null]);
        }
        $this->getJson("/api/agent/scopes/{$foreign->scope_id}/plans?year=2026")->assertForbidden();
    }

    public function test_invalid_plan_payload_returns_422(): void
    {
        [, , $url] = $this->agent();
        $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors(['title', 'month']);
        $this->getJson($url)->assertUnprocessable()->assertJsonValidationErrors(['year']);
        $this->assertDatabaseCount('plan_items', 0);
    }

    public function test_spec_describes_photo_and_plan_workflows(): void
    {
        $this->agent();
        $this->get('/api/agent/spec', ['Accept' => 'text/markdown'])->assertOk()
            ->assertSee('Specification version: 2026-09-29.1', false)
            ->assertSee('/api/agent/scopes/{scope}/plans', false)
            ->assertSee('/api/agent/scopes/{scope}/files/{file}/image', false)
            ->assertSee('photo=1', false)
            ->assertSee('task_ids', false)
            ->assertSee('report.write', false);
    }

    public function test_revoked_token_cannot_read_plans_401(): void
    {
        [$agent, , $url] = $this->agent();
        $agent->tokens()->delete();
        $this->getJson($url.'?year=2026')->assertUnauthorized();
    }
}
