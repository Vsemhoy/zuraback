<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Diagram;
use App\Models\EntityLink;
use App\Models\Project;
use App\Models\Scope;
use App\Models\Task;
use App\Models\Thing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EquipLinksTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): array
    {
        $owner = User::factory()->create();
        $scope = Scope::factory()->create(['owner_id' => $owner->id]);
        $department = Department::factory()->create(['scope_id' => $scope->id]);
        $thing = Thing::factory()->create(['scope_id' => $scope->id, 'department_id' => $department->id]);
        $this->actingAs($owner)->withHeaders(['Accept' => 'application/json', 'X-App-Request' => 'Zuratax']);

        return [$owner, $scope, $thing, "/api/scopes/{$scope->id}/equip/{$thing->id}/tasks"];
    }

    public function test_links_completed_tasks_without_editing_them_and_unlinks_without_deleting(): void
    {
        [$owner, $scope, $thing, $url] = $this->workspace();
        $task = Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'status' => 'done', 'completed_at' => now()]);
        $before = $task->fresh()->getAttributes();
        $this->postJson($url, ['task_id' => $task->id])->assertNoContent();
        $this->postJson($url, ['task_id' => $task->id])->assertNoContent();
        $this->assertDatabaseCount('entity_links', 1);
        $this->assertDatabaseCount('activity_logs', 1);
        $this->assertSame($before, $task->fresh()->getAttributes());
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $task->id)->assertJsonPath('data.0.status', 'done');
        $this->getJson($url.'?candidates=1')->assertOk()->assertJsonCount(0, 'data');
        $link = EntityLink::firstOrFail();
        $this->getJson("/api/scopes/{$scope->id}/links")->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson("/api/scopes/{$scope->id}/links/{$link->id}")->assertNotFound();
        $this->deleteJson("$url/{$task->id}")->assertNoContent();
        $this->assertDatabaseCount('entity_links', 0);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'done']);
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $thing->id, 'action' => 'equip.task_unlinked', 'actor_id' => $owner->id]);
    }

    public function test_department_project_scope_and_readonly_boundaries(): void
    {
        [$owner, $scope, $thing, $url] = $this->workspace();
        $visible = Task::factory()->create(['scope_id' => $scope->id]);
        $private = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'private']);
        $hidden = Task::factory()->create(['scope_id' => $scope->id, 'project_id' => $private->id]);
        $foreign = Task::factory()->create();
        foreach ([$visible, $hidden] as $task) {
            $this->postJson($url, ['task_id' => $task->id])->assertNoContent();
        }
        $member = User::factory()->create();
        $membership = $scope->members()->create(['user_id' => $member->id, 'role' => 'member', 'department_id' => $thing->department_id, 'project_access_mode' => 'all']);
        $this->actingAs($member);
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $visible->id);
        $this->postJson($url, ['task_id' => $visible->id])->assertForbidden();
        $this->deleteJson("$url/{$visible->id}")->assertForbidden();
        $membership->update(['permissions' => ['allow' => ['equip.update']]]);
        $this->postJson($url, ['task_id' => $hidden->id])->assertNotFound();
        $this->postJson($url, ['task_id' => $foreign->id])->assertNotFound();
        $this->deleteJson("$url/{$hidden->id}")->assertNotFound();
        $this->getJson($url.'?candidates=1')->assertOk()->assertJsonCount(0, 'data');
        $membership->update(['department_id' => Department::factory()->create(['scope_id' => $scope->id])->id]);
        $this->getJson($url)->assertNotFound();
        $this->postJson($url, ['task_id' => $visible->id])->assertNotFound();
        $this->assertDatabaseCount('entity_links', 2);
    }

    public function test_candidates_paginate_and_search_and_task_permission_denial_is_enforced(): void
    {
        [, $scope, $thing, $url] = $this->workspace();
        Task::factory()->count(31)->create(['scope_id' => $scope->id, 'title' => 'Обычная задача']);
        $target = Task::factory()->create(['scope_id' => $scope->id, 'title' => 'Заменить монитор']);
        $this->getJson($url.'?candidates=1')->assertOk()->assertJsonCount(30, 'data')->assertJsonPath('meta.has_more', true);
        $this->getJson($url.'?candidates=1&page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.has_more', false);
        $this->getJson($url.'?candidates=1&q='.urlencode('Заменить'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->id);
        $this->postJson($url, ['task_id' => 'wrong'])->assertUnprocessable();
        $member = User::factory()->create();
        $scope->members()->create(['user_id' => $member->id, 'role' => 'admin', 'department_id' => $thing->department_id, 'permissions' => ['deny' => ['task.view']]]);
        $this->actingAs($member);
        $this->getJson($url)->assertForbidden();
        $this->getJson($url.'?candidates=1')->assertForbidden();
        $this->postJson($url, ['task_id' => $target->id])->assertNotFound();
        $this->assertDatabaseCount('entity_links', 0);
    }

    public function test_diagram_links_support_equipment_and_preserve_both_objects(): void
    {
        [$owner, $scope, $thing] = $this->workspace();
        $diagram = Diagram::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'scope']);
        $base = "/api/scopes/{$scope->id}/diagrams";
        $data = ['subject_type' => 'thing', 'subject_id' => $thing->id];
        $response = $this->postJson("$base/{$diagram->id}/attachments", $data)->assertOk()->assertJsonPath('data.attachments.0.type', 'thing')
            ->assertJsonPath('data.attachments.0.href', '/equip?item='.$thing->id);
        $attachment = $response->json('data.attachments.0.attachment_id');
        $this->postJson("$base/{$diagram->id}/attachments", $data)->assertOk();
        $this->assertDatabaseCount('diagram_attachments', 1);
        $this->assertDatabaseCount('activity_logs', 1);
        $this->getJson("$base?subject_type=thing&subject_id={$thing->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("$base/targets?type=thing&q=".urlencode($thing->name))->assertOk()->assertJsonPath('data.0.id', $thing->id);
        $this->deleteJson("$base/{$diagram->id}/attachments/$attachment")->assertNoContent();
        $this->assertDatabaseCount('diagram_attachments', 0);
        $this->assertDatabaseHas('diagrams', ['id' => $diagram->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('things', ['id' => $thing->id]);
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $thing->id, 'action' => 'equip.diagram_unlinked']);
    }

    public function test_diagram_link_checks_private_diagram_department_and_equipment_update(): void
    {
        [$owner, $scope, $thing] = $this->workspace();
        $base = "/api/scopes/{$scope->id}/diagrams";
        $private = Diagram::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'private']);
        $shared = Diagram::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'scope']);
        foreach ([$private, $shared] as $diagram) {
            $this->postJson("$base/{$diagram->id}/attachments", ['subject_type' => 'thing', 'subject_id' => $thing->id])->assertOk();
        }
        $member = User::factory()->create();
        $membership = $scope->members()->create(['user_id' => $member->id, 'role' => 'member', 'project_access_mode' => 'all', 'department_id' => $thing->department_id]);
        $own = Diagram::factory()->create(['scope_id' => $scope->id, 'created_by' => $member->id]);
        $this->actingAs($member);
        $this->getJson("$base?subject_type=thing&subject_id={$thing->id}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $shared->id);
        $this->postJson("$base/{$own->id}/attachments", ['subject_type' => 'thing', 'subject_id' => $thing->id])->assertForbidden();
        $membership->update(['permissions' => ['allow' => ['equip.update']]]);
        $this->postJson("$base/{$own->id}/attachments", ['subject_type' => 'thing', 'subject_id' => $thing->id])->assertOk();
        $foreign = Thing::factory()->create();
        $this->postJson("$base/{$own->id}/attachments", ['subject_type' => 'thing', 'subject_id' => $foreign->id])->assertNotFound();
        $this->postJson("$base/{$shared->id}/attachments", ['subject_type' => 'thing', 'subject_id' => $thing->id])->assertNotFound();
        $membership->update(['department_id' => Department::factory()->create(['scope_id' => $scope->id])->id]);
        $this->getJson("$base?subject_type=thing&subject_id={$thing->id}")->assertNotFound();
        $this->getJson("$base/{$shared->id}")->assertOk()->assertJsonCount(0, 'data.attachments');
        $this->getJson("$base/targets?type=thing")->assertOk()->assertJsonCount(0, 'data');
        $attachment = $own->attachments()->firstOrFail();
        $this->deleteJson("$base/{$own->id}/attachments/{$attachment->id}")->assertNotFound();
    }
}
