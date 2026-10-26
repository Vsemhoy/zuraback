<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\FilerFile;
use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\Project;
use App\Models\Scope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class LedgerPhotosTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): array
    {
        Storage::fake('filer');
        config(['filer.reserve_bytes' => 0]);
        $owner = User::factory()->create();
        $scope = Scope::factory()->create(['owner_id' => $owner->id]);
        $project = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $account = LedgerAccount::factory()->create(['project_id' => $project->id]);
        $transaction = LedgerTransaction::factory()->create(['account_id' => $account->id]);
        $this->actingAs($owner)->withHeaders(['X-App-Request' => 'Zuratax', 'Accept' => 'application/json']);

        return [$owner, $scope, $transaction, "/api/scopes/{$scope->id}"];
    }

    private function upload(string $base, LedgerTransaction $transaction, array $extra = []): TestResponse
    {
        return $this->withHeaders(['Content-Type' => 'multipart/form-data; boundary=test'])->post("$base/ledger/photos", [...[
            'file' => UploadedFile::fake()->image('receipt.jpg', 2200, 1100), 'photo' => '1',
            'category' => 'ledger_transaction', 'subject_type' => 'ledger_transaction',
            'subject_id' => $transaction->id, 'visibility' => 'scope',
        ], ...$extra]);
    }

    public function test_ledger_photos_are_compressed_and_hidden_from_every_general_filer_endpoint(): void
    {
        [, , $transaction, $base] = $this->workspace();
        $id = $this->upload($base, $transaction)->assertCreated()->assertJsonPath('data.container', 'ledger_photos')->json('data.id');
        $file = FilerFile::findOrFail($id);
        $this->assertStringContainsString('/ledger_photos/', $file->path);
        $size = getimagesizefromstring(Storage::disk('filer')->get($file->path));
        $this->assertSame([2000, 1000], array_slice($size, 0, 2));
        $this->getJson("$base/ledger/photos?subject_type=ledger_transaction&subject_id={$transaction->id}&photos=1")->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson("$base/ledger/photos/$id/image")->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->getJson("$base/ledger/photos/$id/download")->assertOk();
        foreach (['', '?photos=1', '?category=ledger_transaction', "?subject_type=ledger_transaction&subject_id={$transaction->id}&photos=1"] as $query) {
            $this->getJson("$base/files$query")->assertOk()->assertJsonCount(0, 'data');
        }
        foreach (['image', 'download', 'preview', 'preview/content'] as $endpoint) {
            $this->getJson("$base/files/$id/$endpoint")->assertNotFound();
        }
        $this->deleteJson("$base/files/$id")->assertNotFound();
        $this->deleteJson("$base/ledger/photos/$id")->assertNoContent();
        Storage::disk('filer')->assertMissing($file->path);
    }

    public function test_finance_only_user_can_upload_but_task_rights_and_cross_scope_do_not_grant_access(): void
    {
        [$owner, $scope, $transaction, $base] = $this->workspace();
        $finance = User::factory()->create();
        $scope->members()->create(['user_id' => $finance->id, 'role' => 'member', 'project_access_mode' => 'all', 'permissions' => ['allow' => ['ledger.view', 'ledger.write'], 'deny' => ['task.view', 'task.update']]]);
        $this->actingAs($finance);
        $id = $this->upload($base, $transaction)->assertCreated()->json('data.id');
        $this->getJson("$base/ledger/photos/$id/image")->assertOk();
        $other = User::factory()->create();
        $scope->members()->create(['user_id' => $other->id, 'role' => 'member', 'project_access_mode' => 'all']);
        $this->actingAs($other)->getJson("$base/ledger/photos/$id/image")->assertForbidden();
        $this->getJson("$base/files/$id/image")->assertNotFound();
        $otherScope = Scope::factory()->create(['owner_id' => $owner->id]);
        $this->actingAs($owner)->getJson("/api/scopes/{$otherScope->id}/ledger/photos/$id/image")->assertNotFound();
    }

    public function test_transfer_receipts_require_both_accounts_and_deleted_subjects_fail_closed(): void
    {
        [$owner, $scope, $transaction, $base] = $this->workspace();
        $project = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $target = LedgerAccount::factory()->create(['project_id' => $project->id]);
        $transaction->update(['kind' => 'transfer', 'target_account_id' => $target->id]);
        $id = $this->upload($base, $transaction)->assertCreated()->json('data.id');
        $viewer = User::factory()->create();
        $scope->members()->create(['user_id' => $viewer->id, 'role' => 'member', 'project_access_mode' => 'restricted', 'permissions' => ['allow' => ['ledger.view', 'ledger.write']]]);
        $project->members()->create(['user_id' => $viewer->id, 'is_active' => true]);
        $this->actingAs($viewer)->getJson("$base/ledger/transactions/{$transaction->id}")->assertOk()->assertJsonPath('data.can_view_photos', false);
        $this->getJson("$base/ledger/photos/$id/image")->assertNotFound();
        $this->upload($base, $transaction)->assertNotFound();
        $transaction->delete();
        $this->actingAs($owner)->getJson("$base/ledger/photos/$id/image")->assertNotFound();
        $transaction->restore();
        $this->getJson("$base/ledger/photos/$id/image")->assertOk();
    }

    public function test_photos_cannot_be_reattached_or_uploaded_as_arbitrary_ledger_files(): void
    {
        [, , $transaction, $base] = $this->workspace();
        $this->upload($base, $transaction, ['file' => UploadedFile::fake()->createWithContent('receipt.svg', '<svg/>')])->assertUnprocessable();
        $this->upload($base, $transaction, ['photo' => '0'])->assertUnprocessable();
        $this->assertCount(0, Storage::disk('filer')->allFiles());
        $id = $this->upload($base, $transaction)->assertCreated()->json('data.id');
        $this->postJson("$base/files/$id/attachments", ['subject_type' => 'project', 'subject_id' => $transaction->account->project_id])->assertNotFound();
        $transaction->account->update(['is_archived' => true]);
        $this->upload($base, $transaction)->assertNotFound();
        $this->getJson("$base/ledger/photos/$id/image")->assertOk();
    }

    public function test_event_container_keeps_cover_and_legacy_migration_keeps_bytes_and_references(): void
    {
        [$owner, $scope, , $base] = $this->workspace();
        $event = Event::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'scope']);
        $id = $this->withHeaders(['Content-Type' => 'multipart/form-data; boundary=test'])->post("$base/files", ['file' => UploadedFile::fake()->image('event.png'), 'photo' => '1', 'category' => 'event', 'subject_type' => 'event', 'subject_id' => $event->id, 'visibility' => 'scope'])
            ->assertCreated()->assertJsonPath('data.container', 'event_photos')->json('data.id');
        $this->postJson("$base/files/$id/feature", ['subject_type' => 'event', 'subject_id' => $event->id, 'enabled' => true])->assertNoContent();
        $file = FilerFile::findOrFail($id);
        $file->update(['container' => 'files']);
        $before = Storage::disk('filer')->get($file->path);
        $migration = require glob(database_path('migrations/*_classify_existing_event_photos.php'))[0];
        $migration->up();
        $migration->up();
        $this->assertSame('event_photos', $file->fresh()->container);
        $this->assertSame($before, Storage::disk('filer')->get($file->path));
        $this->assertSame($id, $event->fresh()->meta['cover_file_id']);
        $this->getJson("$base/files")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("$base/files?photos=1&subject_type=event&subject_id={$event->id}")->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson("$base/files/$id/image")->assertOk();
        $this->getJson("$base/ledger/photos/$id/image")->assertNotFound();
    }
}
