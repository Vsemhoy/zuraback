<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\ContentNavigationLink;
use App\Models\Diagram;
use App\Models\FilerFile;
use App\Models\Project;
use App\Models\Scope;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContentNavigationApiTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): array
    {
        $owner = User::factory()->create();
        $scope = Scope::factory()->create(['owner_id' => $owner->id]);
        $source = Diagram::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'scope']);
        $this->actingAs($owner)->withHeaders(['Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-App-Request' => 'Zuratax']);

        return [$owner, $scope, $source, "/api/scopes/{$scope->id}/content-nodes"];
    }

    public function test_reader_returns_complete_document_and_region_without_mutating_diagram_versions(): void
    {
        [$owner, $scope, $source, $base] = $this->workspace();
        $task = Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'project_id' => null]);
        $region = ['page_id' => 'page-1', 'x' => .1, 'y' => .2, 'width' => .3, 'height' => .4];
        $this->postJson("$base/diagram/{$source->id}/links", ['target_type' => 'task', 'target_id' => $task->id, 'region' => $region, 'source_revision' => 1, 'label' => 'Inspect', 'created_by' => 'spoof'])
            ->assertCreated()->assertJsonCount(1, 'data.links')->assertJsonPath('data.links.0.target.id', $task->id)->assertJsonPath('data.can_manage', true);
        $this->getJson("$base/diagram/{$source->id}")->assertOk()->assertJsonPath('data.document.xml', Diagram::EMPTY_XML)->assertJsonPath('data.links.0.region.page_id', 'page-1');
        $this->assertDatabaseHas('content_navigation_links', ['source_id' => $source->id, 'target_id' => $task->id, 'created_by' => $owner->id]);
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $source->id, 'action' => 'content.navigation.created']);
        $this->assertDatabaseHas('diagrams', ['id' => $source->id, 'revision' => 1, 'xml' => Diagram::EMPTY_XML]);
        $this->assertDatabaseCount('diagram_revisions', 0);
    }

    public function test_cycles_and_self_links_are_read_without_recursive_expansion(): void
    {
        [$owner, $scope, $source, $base] = $this->workspace();
        $other = Diagram::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $this->postJson("$base/diagram/{$source->id}/links", ['target_type' => 'diagram', 'target_id' => $other->id])->assertCreated();
        $this->postJson("$base/diagram/{$other->id}/links", ['target_type' => 'diagram', 'target_id' => $source->id])->assertCreated();
        $this->postJson("$base/diagram/{$source->id}/links", ['target_type' => 'diagram', 'target_id' => $source->id])->assertCreated()->assertJsonCount(2, 'data.links');
        $this->getJson("$base/diagram/{$other->id}")->assertOk()->assertJsonPath('data.links.0.target.id', $source->id)->assertJsonMissingPath('data.links.0.target.document');
    }

    public function test_private_targets_and_their_custom_labels_are_not_exposed_to_readers(): void
    {
        [$owner, $scope, $source, $base] = $this->workspace();
        $hidden = Diagram::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'title' => 'Secret target']);
        $this->postJson("$base/diagram/{$source->id}/links", ['target_type' => 'diagram', 'target_id' => $hidden->id, 'label' => 'Secret label'])->assertCreated();
        $reader = User::factory()->create();
        $scope->members()->create(['user_id' => $reader->id, 'role' => 'observer', 'project_access_mode' => 'all']);
        $this->actingAs($reader)->getJson("$base/diagram/{$source->id}")->assertOk()->assertJsonCount(0, 'data.links')->assertJsonPath('data.can_manage', false)->assertDontSee('Secret');
        $this->getJson("$base/diagram/{$hidden->id}")->assertNotFound();
        $this->getJson("$base/targets?type=diagram")->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("$base/diagram/{$source->id}/links", ['target_type' => 'diagram', 'target_id' => $source->id])->assertNotFound();
        $this->assertDatabaseCount('content_navigation_links', 1);
    }

    public function test_foreign_scope_targets_sources_and_link_ids_return_404_without_mutation(): void
    {
        [$owner, $scope, $source, $base] = $this->workspace();
        $foreign = Diagram::factory()->create();
        $this->postJson("$base/diagram/{$source->id}/links", ['target_type' => 'diagram', 'target_id' => $foreign->id])->assertNotFound();
        $this->getJson("$base/diagram/{$foreign->id}")->assertNotFound();
        $link = ContentNavigationLink::factory()->create(['scope_id' => $scope->id, 'source_type' => 'diagram', 'source_id' => $foreign->id, 'target_type' => 'diagram', 'target_id' => $source->id]);
        $this->deleteJson("$base/diagram/{$source->id}/links/{$link->id}")->assertNotFound();
        $this->patchJson("$base/diagram/{$source->id}/links/{$link->id}", ['target_type' => 'diagram', 'target_id' => $source->id])->assertNotFound();
        $this->assertDatabaseHas('content_navigation_links', ['id' => $link->id, 'deleted_at' => null]);
    }

    public function test_region_validation_rejects_overflow_unknown_page_and_stale_revision(): void
    {
        [$owner, $scope, $source, $base] = $this->workspace();
        $url = "$base/diagram/{$source->id}/links";
        $padded = ['target_type' => 'diagram', 'target_id' => $source->id, 'source_revision' => 1,
            'region' => ['page_id' => 'page-1', 'x' => -.05, 'y' => -.05, 'width' => .1, 'height' => .1]];
        $linkId = $this->postJson($url, $padded)->assertCreated()->json('data.links.0.id');
        $this->deleteJson("$url/$linkId")->assertNoContent();
        ContentNavigationLink::withTrashed()->findOrFail($linkId)->forceDelete();
        $payload = ['target_type' => 'diagram', 'target_id' => $source->id, 'source_revision' => 1,
            'region' => ['page_id' => 'page-1', 'x' => .9, 'y' => 0, 'width' => .2, 'height' => .2]];
        $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('region');
        $payload['region']['x'] = .1;
        $payload['region']['page_id'] = 'missing-page';
        $this->postJson($url, $payload)->assertUnprocessable()->assertJsonPath('message', 'Страница схемы не найдена.');
        $payload['region']['page_id'] = 'page-1';
        $source->update(['revision' => 2]);
        $this->postJson($url, $payload)->assertStatus(409)->assertJsonPath('message', 'Схема изменилась. Обновите просмотрщик и проверьте положение рамки.');
        $this->assertDatabaseCount('content_navigation_links', 0);
    }

    public function test_link_update_and_soft_delete_are_audited_and_keep_target_documents(): void
    {
        [$owner, $scope, $source, $base] = $this->workspace();
        $payload = ['target_type' => 'diagram', 'target_id' => $source->id];
        $link = $this->postJson("$base/diagram/{$source->id}/links", $payload)->assertCreated()->json('data.links.0.id');
        $this->patchJson("$base/diagram/{$source->id}/links/$link", [...$payload, 'label' => 'Changed'])->assertOk()->assertJsonPath('data.links.0.label', 'Changed');
        $this->deleteJson("$base/diagram/{$source->id}/links/$link")->assertNoContent();
        $this->assertSoftDeleted('content_navigation_links', ['id' => $link]);
        $this->assertDatabaseHas('diagrams', ['id' => $source->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $source->id, 'action' => 'content.navigation.updated']);
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $source->id, 'action' => 'content.navigation.deleted']);
        $this->getJson("$base/diagram/{$source->id}")->assertOk()->assertJsonCount(0, 'data.links');
    }

    public function test_files_tasks_and_lore_are_read_inside_nodes_without_disclosing_storage_paths(): void
    {
        [$owner, $scope, $source, $base] = $this->workspace();
        $file = FilerFile::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $task = Task::factory()->create(['scope_id' => $scope->id, 'project_id' => null, 'created_by' => $owner->id, 'description' => '# Body', 'result' => 'Result', 'status' => 'done']);
        $this->getJson("$base/file/{$file->id}")->assertOk()->assertJsonPath('data.document.name', 'sample.txt')->assertJsonMissingPath('data.document.path');
        $this->getJson("$base/task/{$task->id}")->assertOk()->assertJsonPath('data.document.description', '# Body')->assertJsonPath('data.document.result', 'Result')->assertJsonPath('data.can_manage', false);
        $this->postJson("$base/task/{$task->id}/links", ['target_type' => 'file', 'target_id' => $file->id])->assertNotFound();
        $lore = $this->postJson("/api/scopes/{$scope->id}/lore", ['code' => 'NODE-TEST', 'type' => 'mechanic', 'importance' => 'mechanic', 'criticality' => 'informational', 'visibility' => 'scope', 'title' => 'Knowledge', 'content' => '# Decision', 'status' => 'active'])->assertOk()->json('data.id');
        $this->getJson("$base/lore/$lore")->assertOk()->assertJsonPath('data.document.current_revision.content', '# Decision');
        $this->postJson("$base/file/{$file->id}/links", ['target_type' => 'lore', 'target_id' => $lore])->assertCreated();
        $this->postJson("$base/lore/$lore/links", ['target_type' => 'diagram', 'target_id' => $source->id])->assertCreated();
    }

    public function test_candidates_paginate_visible_results_and_do_not_leak_private_projects(): void
    {
        [$owner, $scope, $source, $base] = $this->workspace();
        Diagram::factory()->count(31)->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'scope']);
        $member = User::factory()->create();
        $scope->members()->create(['user_id' => $member->id, 'role' => 'member', 'project_access_mode' => 'all']);
        $project = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'private']);
        Diagram::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'project_id' => $project->id, 'visibility' => 'scope', 'title' => 'Hidden project']);
        $this->actingAs($member)->getJson("$base/targets?type=diagram")->assertOk()->assertJsonCount(30, 'data')->assertJsonPath('meta.has_more', true)->assertDontSee('Hidden project');
        $this->getJson("$base/targets?type=diagram&page=2")->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.has_more', false);
        $this->getJson("$base/targets?type=unsupported")->assertUnprocessable()->assertJsonValidationErrors('type');
    }

    public function test_read_only_agent_token_cannot_write_navigation(): void
    {
        [$owner, $scope, $source, $base] = $this->workspace();
        $agent = User::factory()->create(['type' => 'agent']);
        $scope->members()->create(['user_id' => $agent->id, 'role' => 'member', 'project_access_mode' => 'all']);
        Sanctum::actingAs($agent, ['task.view']);
        $url = "/api/agent/scopes/{$scope->id}/content-nodes/diagram/{$source->id}";
        $this->getJson($url)->assertOk()->assertJsonPath('data.can_manage', false);
        $this->postJson($url.'/links', ['target_type' => 'diagram', 'target_id' => $source->id])->assertNotFound();
        $this->assertDatabaseCount('content_navigation_links', 0);
    }

    public function test_unauthenticated_requests_return_401(): void
    {
        $this->getJson('/api/scopes/01aaaaaaaaaaaaaaaaaaaaaaaa/content-nodes/diagram/01aaaaaaaaaaaaaaaaaaaaaaaa')->assertUnauthorized();
    }

    public function test_book_reader_can_read_attached_file_without_task_permission(): void
    {
        [$owner, $scope, $source, $base] = $this->workspace();
        $reader = User::factory()->create();
        $scope->members()->create(['user_id' => $reader->id, 'role' => 'observer', 'project_access_mode' => 'all', 'book_access_mode' => 'all', 'permissions' => ['allow' => ['book.view'], 'deny' => ['task.view']]]);
        $book = Book::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'scope']);
        $file = FilerFile::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'scope']);
        $file->attachments()->create(['subject_type' => 'book', 'subject_id' => $book->id]);
        $this->actingAs($reader)->getJson("$base/file/{$file->id}")->assertOk()->assertJsonPath('data.can_manage', false);
        $this->getJson("$base/targets?type=file")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("$base/diagram/{$source->id}")->assertNotFound();
    }

    public function test_deleted_targets_are_hidden_and_invalid_regions_never_persist(): void
    {
        [$owner, $scope, $source, $base] = $this->workspace();
        $target = Diagram::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $this->postJson("$base/diagram/{$source->id}/links", ['target_type' => 'diagram', 'target_id' => $target->id])->assertCreated();
        $target->delete();
        $this->getJson("$base/diagram/{$source->id}")->assertOk()->assertJsonCount(0, 'data.links');
        foreach ([[], ['page_id' => 'page-1'], ['page_id' => 'page-1', 'x' => 0, 'y' => 0, 'width' => 0, 'height' => .1]] as $region) {
            $this->postJson("$base/diagram/{$source->id}/links", ['target_type' => 'diagram', 'target_id' => $source->id, 'source_revision' => 1, 'region' => $region])->assertUnprocessable();
        }
        $this->assertDatabaseCount('content_navigation_links', 1);
    }

    public function test_raw_graph_and_legacy_pages_without_ids_can_have_regions(): void
    {
        [$owner, $scope, $source, $base] = $this->workspace();
        foreach ([['<mxGraphModel><root/></mxGraphModel>', 'single-page'], ['<mxfile><diagram><mxGraphModel><root/></mxGraphModel></diagram></mxfile>', 'page-0']] as [$xml, $pageId]) {
            $source->update(['xml' => $xml]);
            $this->postJson("$base/diagram/{$source->id}/links", ['target_type' => 'diagram', 'target_id' => $source->id, 'source_revision' => '1', 'region' => ['page_id' => $pageId, 'x' => 0, 'y' => 0, 'width' => .5, 'height' => .5]])->assertCreated();
        }
    }
}
