<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\FilerFile;
use App\Models\Project;
use App\Models\Scope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AgentFilerApiTest extends TestCase
{
    use RefreshDatabase;

    private function agent(array $abilities): array
    {
        Storage::fake('filer');
        config(['filer.reserve_bytes' => 0]);
        $agent = User::factory()->create(['type' => 'agent', 'status' => 'active', 'is_active' => true]);
        $scope = Scope::factory()->create();
        $scope->members()->create(['user_id' => $agent->id, 'role' => 'admin', 'is_active' => true, 'project_access_mode' => 'all', 'book_access_mode' => 'all', 'joined_at' => now()]);
        $this->withToken($agent->createToken('Filer test', $abilities)->plainTextToken)->withHeader('Accept', 'application/json');

        return [$agent, $scope, "/api/agent/scopes/{$scope->id}/files"];
    }

    private function upload(): array
    {
        return ['file' => UploadedFile::fake()->createWithContent('notes.txt', 'agent notes'), 'category' => 'general', 'visibility' => 'private'];
    }

    public function test_agent_can_upload_download_attach_and_delete_own_file(): void
    {
        [$agent, $scope, $url] = $this->agent(['task.view', 'task.update', 'task.delete']);
        $id = $this->post($url, $this->upload())->assertCreated()->json('data.id');
        $file = FilerFile::findOrFail($id);
        Storage::disk('filer')->assertExists($file->path);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.id', $id);
        $this->get("{$url}/{$id}/download")->assertOk()->assertDownload('notes.txt');
        $project = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $agent->id]);
        $this->postJson("{$url}/{$id}/attachments", ['subject_type' => 'project', 'subject_id' => $project->id])->assertOk();
        $this->deleteJson("{$url}/{$id}")->assertNoContent();
        Storage::disk('filer')->assertMissing($file->path);
        $this->assertDatabaseMissing('filer_files', ['id' => $id]);
        $this->assertDatabaseMissing('filer_attachments', ['filer_file_id' => $id]);
    }

    public function test_read_only_token_cannot_write_even_for_own_contractor(): void
    {
        [$agent, $scope, $url] = $this->agent(['task.view']);
        $file = FilerFile::factory()->create(['scope_id' => $scope->id, 'created_by' => $agent->id]);
        $this->getJson($url)->assertOk();
        $this->post($url, array_merge($this->upload(), ['category' => 'user', 'subject_type' => 'user', 'subject_id' => $agent->id]))->assertForbidden();
        $this->postJson("{$url}/{$file->id}/attachments", ['subject_type' => 'user', 'subject_id' => $agent->id])->assertForbidden();
        $this->deleteJson("{$url}/{$file->id}")->assertForbidden();
    }

    public function test_update_token_cannot_physically_delete(): void
    {
        [$agent, $scope, $url] = $this->agent(['task.view', 'task.update']);
        $id = $this->post($url, $this->upload())->assertCreated()->json('data.id');
        $this->deleteJson("{$url}/{$id}")->assertForbidden();
        $this->assertDatabaseHas('filer_files', ['id' => $id]);
    }

    public function test_agent_can_upload_read_and_feature_event_photo(): void
    {
        [$agent, $scope, $url] = $this->agent(['task.view', 'task.update']);
        $event = Event::factory()->create(['scope_id' => $scope->id, 'created_by' => $agent->id]);
        $id = $this->post($url, ['file' => UploadedFile::fake()->image('cover.png', 40, 30), 'photo' => '1',
            'category' => 'event', 'visibility' => 'scope', 'subject_type' => 'event', 'subject_id' => $event->id])
            ->assertCreated()->json('data.id');
        $file = FilerFile::findOrFail($id);
        Storage::disk('filer')->assertExists($file->path);
        $this->assertSame('image/webp', $file->mime);
        $this->getJson($url.'?photos=1&subject_type=event&subject_id='.$event->id)->assertOk()->assertJsonPath('data.0.id', $id);
        $this->get("{$url}/{$id}/image")->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->postJson("{$url}/{$id}/feature", ['subject_type' => 'event', 'subject_id' => $event->id, 'enabled' => true])->assertNoContent();
        $this->assertSame($id, $event->fresh()->meta['cover_file_id']);
        $this->postJson("{$url}/{$id}/feature", ['subject_type' => 'event', 'subject_id' => $event->id, 'enabled' => false])->assertNoContent();
        $this->assertNull($event->fresh()->meta['cover_file_id']);
    }

    public function test_read_only_agent_cannot_select_avatar_403(): void
    {
        [$agent, $scope, $url] = $this->agent(['task.view']);
        $file = FilerFile::factory()->create(['scope_id' => $scope->id, 'created_by' => $agent->id, 'visibility' => 'scope', 'mime' => 'image/webp']);
        $file->attachments()->create(['subject_type' => 'user', 'subject_id' => $agent->id]);
        $this->postJson("{$url}/{$file->id}/feature", ['subject_type' => 'user', 'subject_id' => $agent->id, 'enabled' => true])->assertForbidden();
        $this->assertNull($agent->fresh()->profile['avatar'] ?? null);
    }

    public function test_private_and_foreign_images_remain_inaccessible_404(): void
    {
        [, $scope, $url] = $this->agent(['task.view', 'task.update']);
        $private = FilerFile::factory()->create(['scope_id' => $scope->id, 'visibility' => 'private', 'mime' => 'image/webp']);
        $foreign = FilerFile::factory()->create(['visibility' => 'scope', 'mime' => 'image/webp']);
        foreach ([$private, $foreign] as $file) {
            Storage::disk('filer')->put($file->path, 'private image bytes');
            $this->get("{$url}/{$file->id}/image")->assertNotFound();
            Storage::disk('filer')->assertExists($file->path);
        }
    }

    public function test_agent_cannot_access_private_files_projects_or_other_scopes(): void
    {
        [$agent, $scope, $url] = $this->agent(['task.view', 'task.update', 'task.delete']);
        $private = FilerFile::factory()->create(['scope_id' => $scope->id, 'visibility' => 'private']);
        $project = Project::factory()->create(['scope_id' => $scope->id, 'visibility' => 'private']);
        $linked = FilerFile::factory()->create(['scope_id' => $scope->id, 'visibility' => 'scope']);
        $linked->attachments()->create(['subject_type' => 'project', 'subject_id' => $project->id]);
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');
        foreach ([$private, $linked] as $file) {
            $this->getJson("{$url}/{$file->id}/download")->assertNotFound();
            $this->deleteJson("{$url}/{$file->id}")->assertNotFound();
        }
        $this->post($url, array_merge($this->upload(), ['category' => 'project', 'subject_type' => 'project', 'subject_id' => $project->id]))->assertNotFound();
        $other = Scope::factory()->create();
        $this->getJson("/api/agent/scopes/{$other->id}/files")->assertForbidden();
    }
}
