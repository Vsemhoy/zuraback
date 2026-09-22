<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Event;
use App\Models\FilerFile;
use App\Models\Project;
use App\Models\Scope;
use App\Models\ScopeMember;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class FilerApiTest extends TestCase
{
    use RefreshDatabase;

    private function upload(Scope $scope, User $actor, array $extra = []): TestResponse
    {
        return $this->actingAs($actor)->withHeaders(['X-App-Request' => 'Zuratax', 'Content-Type' => 'multipart/form-data; boundary=test'])
            ->post('/api/scopes/'.$scope->id.'/files', [
                'file' => UploadedFile::fake()->createWithContent('notes.txt', 'private document'),
                'category' => 'general', 'visibility' => 'private', ...$extra,
            ]);
    }

    private function prepare(): array
    {
        Storage::fake('filer');
        config(['filer.reserve_bytes' => 0]);
        $owner = User::factory()->create();
        $scope = Scope::factory()->create(['owner_id' => $owner->id]);

        return [$owner, $scope];
    }

    public function test_upload_download_and_physical_delete(): void
    {
        [$owner, $scope] = $this->prepare();
        $result = $this->upload($scope, $owner)->assertCreated()->assertJsonMissingPath('data.path');
        $file = FilerFile::findOrFail($result->json('data.id'));
        Storage::disk('filer')->assertExists($file->path);
        $this->assertSame(hash('sha256', 'private document'), $file->sha256);
        $this->getJson('/api/scopes/'.$scope->id.'/files/'.$file->id.'/download')->assertOk()->assertDownload('notes.txt');
        $this->getJson('/api/scopes/'.$scope->id.'/files')->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson('/api/scopes/'.$scope->id.'/files/'.$file->id)->assertNoContent();
        Storage::disk('filer')->assertMissing($file->path);
        $this->assertDatabaseMissing('filer_files', ['id' => $file->id]);
    }

    public function test_private_file_hidden_from_colleague_and_cross_scope_download(): void
    {
        [$owner, $scope] = $this->prepare();
        $id = $this->upload($scope, $owner)->assertCreated()->json('data.id');
        $colleague = User::factory()->create();
        ScopeMember::factory()->create(['scope_id' => $scope->id, 'user_id' => $colleague->id, 'project_access_mode' => 'all']);
        $this->actingAs($colleague)->getJson('/api/scopes/'.$scope->id.'/files')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/scopes/'.$scope->id.'/files/'.$id.'/download')->assertNotFound();
        $other = Scope::factory()->create(['owner_id' => $owner->id]);
        $this->actingAs($owner)->getJson('/api/scopes/'.$other->id.'/files/'.$id.'/download')->assertNotFound();
    }

    public function test_project_privacy_change_revokes_existing_attachment_access(): void
    {
        [$owner, $scope] = $this->prepare();
        $project = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'scope']);
        $id = $this->upload($scope, $owner, ['category' => 'project', 'visibility' => 'scope', 'subject_type' => 'project', 'subject_id' => $project->id])->assertCreated()->json('data.id');
        $colleague = User::factory()->create();
        ScopeMember::factory()->create(['scope_id' => $scope->id, 'user_id' => $colleague->id, 'project_access_mode' => 'all']);
        $this->actingAs($colleague)->getJson('/api/scopes/'.$scope->id.'/files/'.$id.'/download')->assertOk();
        $project->update(['visibility' => 'private']);
        $this->getJson('/api/scopes/'.$scope->id.'/files/'.$id.'/download')->assertNotFound();
        $this->getJson('/api/scopes/'.$scope->id.'/files')->assertJsonCount(0, 'data');
    }

    public function test_second_link_does_not_broaden_access(): void
    {
        [$owner, $scope] = $this->prepare();
        $private = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'private']);
        $shared = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'scope']);
        $id = $this->upload($scope, $owner, ['category' => 'project', 'visibility' => 'scope', 'subject_type' => 'project', 'subject_id' => $private->id])->assertCreated()->json('data.id');
        $this->postJson('/api/scopes/'.$scope->id.'/files/'.$id.'/attachments', ['subject_type' => 'project', 'subject_id' => $shared->id])->assertOk()->assertJsonCount(2, 'data.attachments');
        $colleague = User::factory()->create();
        ScopeMember::factory()->create(['scope_id' => $scope->id, 'user_id' => $colleague->id, 'project_access_mode' => 'all']);
        $this->actingAs($colleague)->getJson('/api/scopes/'.$scope->id.'/files/'.$id.'/download')->assertNotFound();
        $this->assertDatabaseCount('filer_files', 1);
    }

    public function test_category_requires_matching_subject_and_cross_scope_is_rejected(): void
    {
        [$owner, $scope] = $this->prepare();
        $this->upload($scope, $owner, ['category' => 'task'])->assertStatus(422);
        $otherProject = Project::factory()->create();
        $this->upload($scope, $owner, ['category' => 'project', 'subject_type' => 'project', 'subject_id' => $otherProject->id])->assertNotFound();
        $this->assertDatabaseCount('filer_files', 0);
        $this->assertSame([], Storage::disk('filer')->allFiles());
    }

    public function test_low_space_and_oversize_upload_are_rejected(): void
    {
        [$owner, $scope] = $this->prepare();
        config(['filer.reserve_bytes' => PHP_INT_MAX]);
        $this->upload($scope, $owner)->assertStatus(507);
        config(['filer.reserve_bytes' => 0]);
        $this->upload($scope, $owner, ['file' => UploadedFile::fake()->create('large.zip', 20481)])->assertStatus(422);
        $this->assertDatabaseCount('filer_files', 0);
    }

    public function test_observer_cannot_upload_and_other_member_cannot_delete_shared_file(): void
    {
        [$owner, $scope] = $this->prepare();
        $id = $this->upload($scope, $owner, ['visibility' => 'scope'])->assertCreated()->json('data.id');
        $observer = User::factory()->create();
        ScopeMember::factory()->create(['scope_id' => $scope->id, 'user_id' => $observer->id, 'role' => 'observer', 'project_access_mode' => 'all']);
        $this->upload($scope, $observer)->assertForbidden();
        $this->deleteJson('/api/scopes/'.$scope->id.'/files/'.$id)->assertNotFound();
        $this->getJson('/api/scopes/'.$scope->id.'/files/'.$id.'/download')->assertOk();
    }

    public function test_task_book_event_and_contractor_attachments_use_existing_entities(): void
    {
        [$owner, $scope] = $this->prepare();
        ScopeMember::factory()->create(['scope_id' => $scope->id, 'user_id' => $owner->id, 'role' => 'admin']);
        $task = Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'project_id' => null]);
        $book = Book::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $event = Event::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'project_id' => null]);
        foreach (['task' => $task, 'book' => $book, 'event' => $event, 'user' => $owner] as $type => $entity) {
            $this->upload($scope, $owner, ['category' => $type, 'subject_type' => $type, 'subject_id' => $entity->id])
                ->assertCreated()->assertJsonPath('data.attachments.0.id', $entity->id);
        }
    }

    public function test_guest_cannot_read_files_and_multipart_is_not_allowed_for_other_routes(): void
    {
        [$owner, $scope] = $this->prepare();
        $this->withHeaders(['X-App-Request' => 'Zuratax'])->getJson('/api/scopes/'.$scope->id.'/files')->assertUnauthorized();
        $this->actingAs($owner)->withHeaders(['Content-Type' => 'multipart/form-data; boundary=test'])
            ->post('/api/scopes', ['name' => 'unexpected'])->assertStatus(415);
    }

    public function test_deleted_subject_remains_manageable_by_uploader_only(): void
    {
        [$owner, $scope] = $this->prepare();
        $project = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'scope']);
        $id = $this->upload($scope, $owner, ['category' => 'project', 'visibility' => 'scope', 'subject_type' => 'project', 'subject_id' => $project->id])->assertCreated()->json('data.id');
        $project->delete();
        $colleague = User::factory()->create();
        ScopeMember::factory()->create(['scope_id' => $scope->id, 'user_id' => $colleague->id, 'project_access_mode' => 'all']);
        $this->actingAs($colleague)->getJson('/api/scopes/'.$scope->id.'/files/'.$id.'/download')->assertNotFound();
        $this->actingAs($owner)->getJson('/api/scopes/'.$scope->id.'/files')->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson('/api/scopes/'.$scope->id.'/files/'.$id)->assertNoContent();
    }

    public function test_target_search_excludes_private_projects(): void
    {
        [$owner, $scope] = $this->prepare();
        Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'private', 'title' => 'Hidden']);
        $shared = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'scope', 'title' => 'Shared']);
        $colleague = User::factory()->create();
        ScopeMember::factory()->create(['scope_id' => $scope->id, 'user_id' => $colleague->id, 'project_access_mode' => 'all']);
        $this->actingAs($colleague)->withHeaders(['X-App-Request' => 'Zuratax'])
            ->getJson('/api/scopes/'.$scope->id.'/files/targets?type=project')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $shared->id);
    }
}
