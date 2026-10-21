<?php

namespace Tests\Feature;

use App\Models\Diagram;
use App\Models\PlanItem;
use App\Models\Project;
use App\Models\Scope;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DiagramApiTest extends TestCase
{
    use RefreshDatabase;

    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg"><text>Схема</text></svg>';

    private function workspace(): array
    {
        $owner = User::factory()->create();
        $scope = Scope::factory()->create(['owner_id' => $owner->id]);
        $this->actingAs($owner)->withHeaders(['Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-App-Request' => 'Zuratax']);

        return [$owner, $scope, "/api/scopes/{$scope->id}/diagrams"];
    }

    private function colleague(Scope $scope, string $role = 'member', string $mode = 'all'): User
    {
        $user = User::factory()->create();
        $scope->members()->create(['user_id' => $user->id, 'role' => $role, 'project_access_mode' => $mode]);

        return $user;
    }

    public function test_creates_local_xml_and_revision_with_audit_and_ignores_untrusted_ownership(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $stranger = User::factory()->create();
        $id = $this->postJson($url, ['title' => 'Warehouse flow', 'xml' => Diagram::EMPTY_XML, 'preview_svg' => self::SVG, 'created_by' => $stranger->id, 'scope_id' => 'fake'])
            ->assertCreated()->assertJsonPath('data.revision', 1)->assertJsonPath('data.visibility', 'private')
            ->assertJsonPath('data.allow_member_editing', false)->assertJsonPath('data.can_edit', true)
            ->assertJsonPath('data.creator.id', $owner->id)->assertJsonPath('data.xml', Diagram::EMPTY_XML)->json('data.id');

        $this->assertDatabaseHas('diagrams', ['id' => $id, 'scope_id' => $scope->id, 'created_by' => $owner->id, 'xml' => Diagram::EMPTY_XML]);
        $this->assertDatabaseHas('diagram_revisions', ['diagram_id' => $id, 'number' => 1, 'preview_svg' => self::SVG]);
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $id, 'action' => 'diagram.created']);
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.xml')->assertJsonMissingPath('data.0.preview_svg');
    }

    public function test_partial_settings_update_preserves_source_and_stale_revision_returns_409(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $item = Diagram::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'preview_svg' => self::SVG]);
        $this->patchJson($url.'/'.$item->id, ['title' => 'Changed', 'revision' => 1])->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.xml', Diagram::EMPTY_XML)->assertJsonPath('data.preview_svg', self::SVG);
        $this->patchJson($url.'/'.$item->id, ['title' => 'Lost overwrite', 'revision' => 1])->assertStatus(409);
        $this->assertDatabaseHas('diagrams', ['id' => $item->id, 'title' => 'Changed', 'revision' => 2]);
        $this->assertDatabaseCount('diagram_revisions', 1);
        $this->patchJson($url.'/'.$item->id, ['title' => 'Changed', 'revision' => 2])->assertOk()->assertJsonPath('data.revision', 2);
        $this->assertDatabaseCount('diagram_revisions', 1);
        $this->patchJson($url.'/'.$item->id, ['title' => 'Without concurrency guard'])->assertUnprocessable()->assertJsonValidationErrors('revision');
    }

    public function test_xml_only_update_clears_outdated_preview_and_preserves_a_full_multi_page_document(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $item = Diagram::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'preview_svg' => self::SVG]);
        $xml = '<mxfile><diagram name="First">compressed-1</diagram><diagram name="Second">compressed-2</diagram></mxfile>';
        $this->patchJson($url.'/'.$item->id, ['revision' => 1, 'xml' => $xml])->assertOk()->assertJsonPath('data.xml', $xml)->assertJsonPath('data.preview_svg', null);
        $this->assertDatabaseHas('diagram_revisions', ['diagram_id' => $item->id, 'xml' => $xml, 'preview_svg' => null]);
    }

    public function test_malformed_xml_dtd_and_empty_source_are_rejected_without_data_loss(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $item = Diagram::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        foreach (['', '<mxfile><', '<svg/>', '<!DOCTYPE mxfile [<!ENTITY secret SYSTEM "file:///etc/passwd">]><mxfile>&secret;</mxfile>'] as $xml) {
            $this->patchJson($url.'/'.$item->id, ['revision' => 1, 'xml' => $xml])->assertUnprocessable()->assertJsonValidationErrors('xml');
        }
        $this->assertDatabaseHas('diagrams', ['id' => $item->id, 'revision' => 1, 'xml' => Diagram::EMPTY_XML]);
        $this->assertDatabaseCount('diagram_revisions', 0);
        $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('title');
    }

    public function test_duplicate_is_independent_private_and_does_not_copy_attachments_or_history(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $item = Diagram::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'scope', 'allow_member_editing' => true, 'revision' => 7, 'preview_svg' => self::SVG]);
        $task = Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $item->attachments()->create(['subject_type' => 'task', 'subject_id' => $task->id, 'created_by' => $owner->id]);
        $copy = $this->postJson($url.'/'.$item->id.'/duplicate', ['title' => 'Independent copy'])->assertCreated()->assertJsonPath('data.visibility', 'private')
            ->assertJsonPath('data.revision', 1)->assertJsonPath('data.allow_member_editing', false)->assertJsonPath('data.xml', Diagram::EMPTY_XML)->assertJsonCount(0, 'data.attachments')->json('data.id');
        $this->assertNotSame($item->id, $copy);
        $this->assertDatabaseHas('diagram_revisions', ['diagram_id' => $copy, 'number' => 1]);
        $this->patchJson($url.'/'.$copy, ['revision' => 1, 'title' => 'Different'])->assertOk();
        $this->assertDatabaseHas('diagrams', ['id' => $item->id, 'revision' => 7]);
        $this->assertDatabaseCount('diagram_attachments', 1);
    }

    public function test_private_diagram_does_not_become_public_when_attached_to_a_shared_task(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $item = Diagram::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $task = Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'project_id' => null]);
        $payload = ['subject_type' => 'task', 'subject_id' => $task->id];
        $this->postJson($url.'/'.$item->id.'/attachments', $payload)->assertOk()->assertJsonCount(1, 'data.attachments');
        $this->postJson($url.'/'.$item->id.'/attachments', $payload)->assertOk()->assertJsonCount(1, 'data.attachments');
        $this->getJson($url.'?'.http_build_query($payload))->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->colleague($scope))->getJson($url.'?'.http_build_query($payload))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($url.'/'.$item->id)->assertNotFound();
        $this->postJson($url.'/'.$item->id.'/duplicate', [])->assertNotFound();
        $this->assertDatabaseCount('diagram_attachments', 1);
    }

    public function test_shared_diagrams_are_readable_but_only_author_or_owner_can_modify(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $member = $this->colleague($scope);
        $item = Diagram::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'scope']);
        $this->actingAs($member)->getJson($url.'/'.$item->id)->assertOk()->assertJsonPath('data.can_manage', false);
        $this->patchJson($url.'/'.$item->id, ['revision' => 1, 'title' => 'Overwrite'])->assertNotFound();
        $this->postJson($url.'/'.$item->id.'/duplicate', [])->assertCreated()->assertJsonPath('data.creator.id', $member->id)->assertJsonPath('data.visibility', 'private');
        $this->assertDatabaseHas('diagrams', ['id' => $item->id, 'revision' => 1]);
    }

    public function test_scope_members_can_edit_when_enabled_and_owner_can_revoke_permission(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $member = $this->colleague($scope);
        $id = $this->postJson($url, ['title' => 'Collaborative', 'visibility' => 'scope', 'allow_member_editing' => true])
            ->assertCreated()->assertJsonPath('data.allow_member_editing', true)->json('data.id');
        $this->actingAs($member)->getJson($url.'/'.$id)->assertOk()
            ->assertJsonPath('data.can_edit', true)->assertJsonPath('data.can_manage', false)->assertJsonPath('data.can_delete', false);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.allow_member_editing', true);
        $xml = '<mxfile><diagram name="Member edit">member-data</diagram></mxfile>';

        $this->patchJson($url.'/'.$id, ['revision' => 1, 'xml' => $xml, 'preview_svg' => self::SVG])->assertOk()
            ->assertJsonPath('data.xml', $xml)->assertJsonPath('data.revision', 2)->assertJsonPath('data.creator.id', $owner->id);

        $this->assertDatabaseHas('diagram_revisions', ['diagram_id' => $id, 'number' => 2, 'created_by' => $member->id, 'xml' => $xml]);
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $id, 'action' => 'diagram.updated', 'actor_id' => $member->id]);
        $this->patchJson($url.'/'.$id, ['revision' => 1, 'xml' => Diagram::EMPTY_XML])->assertConflict();
        $this->actingAs($owner)->patchJson($url.'/'.$id, ['revision' => 2, 'allow_member_editing' => false])->assertOk()->assertJsonPath('data.revision', 3);
        $this->actingAs($member)->getJson($url.'/'.$id)->assertOk()->assertJsonPath('data.can_edit', false);
        $this->patchJson($url.'/'.$id, ['revision' => 3, 'title' => 'Denied after revocation'])->assertNotFound();
        $this->assertDatabaseHas('diagrams', ['id' => $id, 'allow_member_editing' => false, 'revision' => 3, 'title' => 'Collaborative', 'xml' => $xml]);
    }

    public function test_project_collaboration_requires_project_write_access(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $member = $this->colleague($scope, 'member', 'restricted');
        $project = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'scope']);
        $membership = $project->members()->create(['user_id' => $member->id, 'assigned_by' => $owner->id]);
        $item = Diagram::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'project_id' => $project->id, 'visibility' => 'scope', 'allow_member_editing' => true]);

        $this->actingAs($member)->patchJson($url.'/'.$item->id, ['revision' => 1, 'description' => 'Project edit'])->assertOk()->assertJsonPath('data.can_edit', true);

        $this->assertDatabaseHas('diagrams', ['id' => $item->id, 'description' => 'Project edit', 'revision' => 2]);
        $membership->update(['permissions' => ['deny' => ['task.update']]]);
        $this->getJson($url.'/'.$item->id)->assertOk()->assertJsonPath('data.can_edit', false);
        $this->patchJson($url.'/'.$item->id, ['revision' => 2, 'description' => 'Denied edit'])->assertNotFound();
        $this->actingAs($this->colleague($scope, 'member', 'restricted'))->getJson($url.'/'.$item->id)->assertNotFound();
        $this->patchJson($url.'/'.$item->id, ['revision' => 2, 'description' => 'Hidden edit'])->assertNotFound();
        $this->assertDatabaseHas('diagrams', ['id' => $item->id, 'description' => 'Project edit', 'revision' => 2]);
    }

    public function test_collaboration_does_not_expose_private_diagrams_projects_or_other_scopes(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $member = $this->colleague($scope);
        $private = Diagram::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'allow_member_editing' => true]);
        $project = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'private']);
        $hiddenProject = Diagram::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'project_id' => $project->id, 'visibility' => 'scope', 'allow_member_editing' => true]);
        $foreign = Diagram::factory()->create(['visibility' => 'scope', 'allow_member_editing' => true]);

        $this->actingAs($member)->getJson($url)->assertOk()->assertJsonCount(0, 'data');
        foreach ([$private, $hiddenProject, $foreign] as $item) {
            $this->getJson($url.'/'.$item->id)->assertNotFound();
            $this->patchJson($url.'/'.$item->id, ['revision' => 1, 'title' => 'Denied'])->assertNotFound();
            $this->getJson("/api/scopes/{$scope->id}/content-nodes/diagram/{$item->id}")->assertNotFound();
            $this->assertDatabaseHas('diagrams', ['id' => $item->id, 'revision' => 1, 'title' => $item->title]);
        }
    }

    public function test_collaborators_cannot_change_sharing_project_links_or_delete(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $member = $this->colleague($scope);
        $scope->members()->where('user_id', $member->id)->update(['permissions' => ['allow' => ['task.delete']]]);
        $item = Diagram::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'scope', 'allow_member_editing' => true]);
        $task = Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $this->actingAs($member);

        foreach ([['visibility' => 'private'], ['project_id' => null], ['allow_member_editing' => false]] as $settings) {
            $this->patchJson($url.'/'.$item->id, [...$settings, 'revision' => 1, 'xml' => '<mxfile><diagram>Denied</diagram></mxfile>'])->assertNotFound();
        }
        $this->postJson($url.'/'.$item->id.'/attachments', ['subject_type' => 'task', 'subject_id' => $task->id])->assertNotFound();
        $this->postJson("/api/scopes/{$scope->id}/content-nodes/diagram/{$item->id}/links", ['target_type' => 'task', 'target_id' => $task->id])->assertNotFound();
        $this->deleteJson($url.'/'.$item->id)->assertNotFound();

        $this->assertDatabaseHas('diagrams', ['id' => $item->id, 'visibility' => 'scope', 'allow_member_editing' => true, 'revision' => 1, 'xml' => Diagram::EMPTY_XML, 'deleted_at' => null]);
        $this->assertDatabaseCount('diagram_attachments', 0);
        $this->assertDatabaseCount('content_navigation_links', 0);
        $this->assertDatabaseCount('diagram_revisions', 0);
    }

    public function test_diagram_browser_exposes_edit_permission_without_navigation_management(): void
    {
        [$owner, $scope] = $this->workspace();
        $member = $this->colleague($scope);
        $item = Diagram::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'scope', 'allow_member_editing' => true]);

        $this->actingAs($member)->getJson("/api/scopes/{$scope->id}/content-nodes/diagram/{$item->id}")
            ->assertOk()->assertJsonPath('data.can_edit', true)->assertJsonPath('data.can_manage', false)
            ->assertJsonPath('data.document.xml', Diagram::EMPTY_XML);
    }

    public function test_collaboration_does_not_override_observer_or_denied_capabilities(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $observer = $this->colleague($scope, 'observer');
        $denied = $this->colleague($scope);
        $scope->members()->where('user_id', $denied->id)->update(['permissions' => ['deny' => ['task.update']]]);
        $item = Diagram::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'scope', 'allow_member_editing' => true]);

        foreach ([$observer, $denied] as $actor) {
            $this->actingAs($actor)->getJson($url.'/'.$item->id)->assertOk()->assertJsonPath('data.can_edit', false);
            $this->patchJson($url.'/'.$item->id, ['revision' => 1, 'title' => 'Denied'])->assertForbidden();
        }

        $this->assertDatabaseHas('diagrams', ['id' => $item->id, 'revision' => 1, 'title' => $item->title]);
    }

    public function test_agent_collaboration_respects_token_write_abilities(): void
    {
        [$owner, $scope] = $this->workspace();
        $agent = User::factory()->agent()->create();
        $scope->members()->create(['user_id' => $agent->id, 'role' => 'member', 'project_access_mode' => 'all']);
        $item = Diagram::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'scope', 'allow_member_editing' => true]);
        $url = "/api/agent/scopes/{$scope->id}/diagrams/{$item->id}";
        Sanctum::actingAs($agent, ['task.view']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.can_edit', false);
        $this->patchJson($url, ['revision' => 1, 'title' => 'Denied'])->assertForbidden();
        Sanctum::actingAs($agent, ['task.view', 'task.update']);

        $this->patchJson($url, ['revision' => 1, 'description' => 'Agent collaboration'])
            ->assertOk()->assertJsonPath('data.can_edit', true)->assertJsonPath('data.can_manage', false);

        $this->assertDatabaseHas('diagrams', ['id' => $item->id, 'description' => 'Agent collaboration', 'revision' => 2]);
        $this->assertDatabaseHas('diagram_revisions', ['diagram_id' => $item->id, 'created_by' => $agent->id, 'number' => 2]);
    }

    public function test_member_editing_setting_requires_a_boolean(): void
    {
        [$owner, $scope, $url] = $this->workspace();

        $this->postJson($url, ['title' => 'Bad setting', 'allow_member_editing' => 'everyone'])
            ->assertUnprocessable()->assertJsonValidationErrors('allow_member_editing');

        $this->assertDatabaseCount('diagrams', 0);
    }

    public function test_private_projects_and_cross_scope_requests_do_not_leak_diagrams_or_versions(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $project = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'private']);
        $item = Diagram::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'project_id' => $project->id, 'visibility' => 'scope']);
        $this->actingAs($this->colleague($scope))->getJson($url)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($url.'/'.$item->id)->assertNotFound();
        $this->getJson($url.'/'.$item->id.'/revisions')->assertNotFound();
        $foreign = Scope::factory()->create(['owner_id' => $owner->id]);
        $this->actingAs($owner)->getJson("/api/scopes/{$foreign->id}/diagrams/{$item->id}")->assertNotFound();
        $this->postJson($url, ['title' => 'Foreign project', 'project_id' => Project::factory()->create(['scope_id' => $foreign->id])->id])->assertUnprocessable()->assertJsonValidationErrors('project_id');
    }

    public function test_restricted_member_cannot_use_unprojected_diagrams_or_assign_a_hidden_project(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $member = $this->colleague($scope, 'member', 'restricted');
        $project = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $this->actingAs($member)->postJson($url, ['title' => 'Unprojected'])->assertForbidden();
        $this->postJson($url, ['title' => 'Hidden project', 'project_id' => $project->id])->assertForbidden();
        $this->assertDatabaseCount('diagrams', 0);
    }

    public function test_attachments_require_target_access_and_cannot_mutate_frozen_tasks(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $item = Diagram::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $foreignTask = Task::factory()->create();
        $this->postJson($url.'/'.$item->id.'/attachments', ['subject_type' => 'task', 'subject_id' => $foreignTask->id])->assertNotFound();
        $done = Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'status' => 'done', 'project_id' => null]);
        $this->postJson($url.'/'.$item->id.'/attachments', ['subject_type' => 'task', 'subject_id' => $done->id])->assertForbidden();
        $this->assertDatabaseCount('diagram_attachments', 0);
    }

    public function test_versions_are_retained_and_soft_deletion_hides_all_surfaces(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $id = $this->postJson($url, ['title' => 'Versioned'])->assertCreated()->json('data.id');
        $this->patchJson($url.'/'.$id, ['revision' => 1, 'title' => 'Version 2'])->assertOk();
        $history = $this->getJson($url.'/'.$id.'/revisions')->assertOk()->assertJsonCount(2, 'data')->json('data');
        $this->getJson($url.'/'.$id.'/revisions/'.$history[1]['id'])->assertOk()->assertJsonPath('data.title', 'Versioned')->assertJsonPath('data.xml', Diagram::EMPTY_XML);
        $other = Diagram::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $this->getJson($url.'/'.$other->id.'/revisions/'.$history[0]['id'])->assertNotFound();
        $this->deleteJson($url.'/'.$id)->assertNoContent();
        $this->assertSoftDeleted('diagrams', ['id' => $id]);
        $this->assertDatabaseCount('diagram_revisions', 2);
        $this->getJson($url.'/'.$id)->assertNotFound();
        $this->getJson($url.'/'.$id.'/revisions')->assertNotFound();
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $id, 'action' => 'diagram.deleted']);
    }

    public function test_observers_and_agents_are_limited_by_capabilities(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $this->actingAs($this->colleague($scope, 'observer'))->postJson($url, ['title' => 'Denied'])->assertForbidden();
        $agent = User::factory()->create(['type' => 'agent']);
        $scope->members()->create(['user_id' => $agent->id, 'role' => 'member', 'project_access_mode' => 'all']);
        Sanctum::actingAs($agent, ['task.view', 'task.create', 'task.update']);
        $agentUrl = "/api/agent/scopes/{$scope->id}/diagrams";
        $id = $this->postJson($agentUrl, ['title' => 'Agent flow'])->assertCreated()->json('data.id');
        $this->patchJson($agentUrl.'/'.$id, ['revision' => 1, 'description' => 'Agent note'])->assertOk();
        $this->getJson($agentUrl)->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson($agentUrl.'/'.$id)->assertForbidden();
        $this->get('/api/agent/spec', ['Accept' => 'text/markdown'])->assertOk()->assertSee('## Diagrams API', false);
        $this->assertDatabaseHas('diagrams', ['id' => $id, 'description' => 'Agent note']);
    }

    public function test_unauthenticated_agent_request_returns_401(): void
    {
        $this->getJson('/api/agent/scopes/01aaaaaaaaaaaaaaaaaaaaaaaa/diagrams')->assertUnauthorized();
    }

    public function test_history_retention_keeps_the_latest_hundred_versions(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $item = Diagram::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'revision' => 100]);
        for ($number = 1; $number <= 100; $number++) {
            $item->revisions()->create(['created_by' => $owner->id, 'number' => $number, 'title' => 'v'.$number, 'xml' => Diagram::EMPTY_XML]);
        }
        $this->patchJson($url.'/'.$item->id, ['revision' => 100, 'title' => 'Version 101'])->assertOk()->assertJsonPath('data.revision', 101);
        $this->assertDatabaseCount('diagram_revisions', 100);
        $this->assertDatabaseMissing('diagram_revisions', ['diagram_id' => $item->id, 'number' => 1]);
        $this->assertDatabaseHas('diagram_revisions', ['diagram_id' => $item->id, 'number' => 101]);
    }

    public function test_schemes_can_be_attached_to_a_contractor_without_exposing_foreign_people(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $item = Diagram::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $person = $this->colleague($scope);
        $this->postJson($url.'/'.$item->id.'/attachments', ['subject_type' => 'user', 'subject_id' => $person->id])
            ->assertOk()->assertJsonPath('data.attachments.0.type', 'user')->assertJsonPath('data.attachments.0.title', $person->name);
        $stranger = User::factory()->create();
        $this->postJson($url.'/'.$item->id.'/attachments', ['subject_type' => 'user', 'subject_id' => $stranger->id])->assertNotFound();
        $this->assertDatabaseCount('diagram_attachments', 1);
    }

    public function test_plan_attachments_respect_report_permissions_on_read_and_write(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $member = $this->colleague($scope);
        $plan = PlanItem::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'project_id' => null]);
        $item = Diagram::factory()->create(['scope_id' => $scope->id, 'created_by' => $member->id]);
        $membership = $scope->members()->where('user_id', $member->id)->first();
        $membership->update(['permissions' => ['deny' => ['report.write']]]);
        $this->actingAs($member)->postJson($url.'/'.$item->id.'/attachments', ['subject_type' => 'plan_item', 'subject_id' => $plan->id])->assertForbidden();
        $membership->update(['permissions' => ['deny' => ['report.view']]]);
        $this->getJson($url.'/targets?type=plan_item')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($url.'?subject_type=plan_item&subject_id='.$plan->id)->assertNotFound();
        $this->assertDatabaseCount('diagram_attachments', 0);
    }
}
