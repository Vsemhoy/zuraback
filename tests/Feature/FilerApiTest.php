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

    public function test_photo_is_compressed_and_private_image_access_is_enforced(): void
    {
        [$owner, $scope] = $this->prepare();
        $task = Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $id = $this->upload($scope, $owner, [
            'file' => UploadedFile::fake()->image('camera.png', 2400, 1200), 'photo' => '1',
            'category' => 'task', 'subject_type' => 'task', 'subject_id' => $task->id,
        ])->assertCreated()->assertJsonPath('data.mime', 'image/webp')->json('data.id');
        $file = FilerFile::findOrFail($id);
        $bytes = Storage::disk('filer')->get($file->path);
        $size = getimagesizefromstring($bytes);
        $this->assertSame(2000, $size[0]);
        $this->assertSame(1000, $size[1]);
        $this->assertSame(strlen($bytes), $file->size);
        $this->assertSame(hash('sha256', $bytes), $file->sha256);
        $this->assertCount(1, Storage::disk('filer')->allFiles());
        $this->getJson("/api/scopes/{$scope->id}/files/{$id}/image")->assertOk()->assertHeader('Content-Type', 'image/webp');
        $colleague = User::factory()->create();
        ScopeMember::factory()->create(['scope_id' => $scope->id, 'user_id' => $colleague->id]);
        $this->actingAs($colleague)->getJson("/api/scopes/{$scope->id}/files/{$id}/image")->assertNotFound();
        $otherScope = Scope::factory()->create(['owner_id' => $owner->id]);
        $this->actingAs($owner)->getJson("/api/scopes/{$otherScope->id}/files/{$id}/image")->assertNotFound();
    }

    public function test_invalid_photo_is_rejected_without_leaving_a_file(): void
    {
        [$owner, $scope] = $this->prepare();
        $this->upload($scope, $owner, ['photo' => '1'])->assertUnprocessable();
        $this->upload($scope, $owner, ['photo' => '1', 'file' => UploadedFile::fake()->createWithContent('unsafe.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')])->assertUnprocessable();
        $this->assertSame([], Storage::disk('filer')->allFiles());
        $this->assertDatabaseCount('filer_files', 0);
    }

    public function test_event_photos_have_a_selectable_cover_and_deletion_clears_it(): void
    {
        [$owner, $scope] = $this->prepare();
        $event = Event::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'scope']);
        $id = $this->upload($scope, $owner, [
            'file' => UploadedFile::fake()->image('event.jpg'), 'photo' => '1', 'visibility' => 'scope',
            'category' => 'event', 'subject_type' => 'event', 'subject_id' => $event->id,
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/scopes/{$scope->id}/files/{$id}/feature", [
            'subject_type' => 'event', 'subject_id' => $event->id, 'enabled' => true,
        ])->assertNoContent();
        $this->assertSame($id, $event->fresh()->meta['cover_file_id']);
        $other = Event::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $this->postJson("/api/scopes/{$scope->id}/files/{$id}/feature", [
            'subject_type' => 'event', 'subject_id' => $other->id, 'enabled' => true,
        ])->assertUnprocessable();
        $this->deleteJson("/api/scopes/{$scope->id}/files/{$id}")->assertNoContent();
        $this->assertNull($event->fresh()->meta['cover_file_id'] ?? null);
    }

    public function test_avatar_can_be_seen_by_colleague_but_other_profile_files_stay_private(): void
    {
        [$owner, $scope] = $this->prepare();
        $id = $this->upload($scope, $owner, [
            'file' => UploadedFile::fake()->image('avatar.png', 800, 800), 'photo' => '1', 'visibility' => 'scope',
            'category' => 'user', 'subject_type' => 'user', 'subject_id' => $owner->id,
        ])->assertCreated()->json('data.id');
        $otherId = $this->upload($scope, $owner, [
            'file' => UploadedFile::fake()->image('other.png'), 'photo' => '1', 'visibility' => 'scope',
            'category' => 'user', 'subject_type' => 'user', 'subject_id' => $owner->id,
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/scopes/{$scope->id}/files/{$id}/feature", [
            'subject_type' => 'user', 'subject_id' => $owner->id, 'enabled' => true,
        ])->assertNoContent();
        $this->assertSame($id, $owner->fresh()->profile['avatar']['file_id']);
        $file = FilerFile::findOrFail($id);
        $this->assertSame(512, getimagesizefromstring(Storage::disk('filer')->get($file->path))[0]);
        $colleague = User::factory()->create();
        ScopeMember::factory()->create(['scope_id' => $scope->id, 'user_id' => $colleague->id, 'permissions' => ['allow' => ['task.view'], 'deny' => ['contractor.manage']]]);
        $this->actingAs($colleague)->getJson("/api/scopes/{$scope->id}/files/{$id}/image")->assertOk();
        $this->getJson("/api/scopes/{$scope->id}/files/{$otherId}/image")->assertNotFound();
        $this->postJson("/api/scopes/{$scope->id}/files/{$id}/feature", [
            'subject_type' => 'user', 'subject_id' => $owner->id, 'enabled' => false,
        ])->assertNotFound();
    }

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

    public function test_closed_task_attachments_stay_readable_but_cannot_be_modified(): void
    {
        [$owner, $scope] = $this->prepare();
        $task = Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $id = $this->upload($scope, $owner, ['category' => 'task', 'subject_type' => 'task', 'subject_id' => $task->id])->assertCreated()->json('data.id');
        $task->update(['status' => 'done']);
        $url = "/api/scopes/{$scope->id}/files";
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.can_manage', false);
        $this->getJson("{$url}/{$id}/download")->assertOk();
        $this->withHeaders(['X-App-Request' => 'Zuratax'])->patchJson("{$url}/{$id}", ['description' => 'Changed'])->assertNotFound();
        $this->deleteJson("{$url}/{$id}")->assertNotFound();
        $this->upload($scope, $owner, ['category' => 'task', 'subject_type' => 'task', 'subject_id' => $task->id])->assertNotFound();
        $this->assertDatabaseHas('filer_files', ['id' => $id, 'description' => null]);
        $this->assertDatabaseCount('filer_files', 1);
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
