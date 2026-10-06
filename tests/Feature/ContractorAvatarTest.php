<?php

namespace Tests\Feature;

use App\Models\FilerFile;
use App\Models\Scope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ContractorAvatarTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['X-App-Request' => 'Zuratax'];

    private function workspace(): array
    {
        $owner = User::factory()->create();
        $scope = Scope::query()->create(['owner_id' => $owner->id, 'name' => 'Avatars', 'slug' => 'avatars']);
        $member = User::factory()->create(['profile' => ['bio' => 'Keep me']]);
        $scope->members()->create(['user_id' => $member->id, 'role' => 'member', 'joined_at' => now()]);

        return [$owner, $scope, $member];
    }

    private function avatar(): array
    {
        return ['preset' => 'Anima_00024_.png', 'crop' => ['x' => 25, 'y' => 75, 'zoom' => 2]];
    }

    public function test_owner_can_save_another_members_avatar_and_crop_without_losing_profile(): void
    {
        [$owner, $scope, $member] = $this->workspace();
        $this->actingAs($owner)->withHeaders(self::HEADERS)->patchJson("/api/scopes/{$scope->id}/contractors/{$member->id}/avatar", ['avatar' => $this->avatar()])
            ->assertOk()->assertJsonPath('data.avatar.crop.zoom', 2);
        $this->assertSame($this->avatar(), $member->fresh()->profile['avatar']);
        $this->assertSame('Keep me', $member->fresh()->profile['bio']);
    }

    #[TestWith(['admin'])]
    #[TestWith(['member'])]
    #[TestWith(['observer'])]
    public function test_non_owner_can_change_self_but_receives_403_for_other_users(string $role): void
    {
        [$owner, $scope, $member] = $this->workspace();
        $scope->members()->where('user_id', $member->id)->update(['role' => $role]);
        $this->actingAs($member)->withHeaders(self::HEADERS)->patchJson("/api/scopes/{$scope->id}/contractors/{$member->id}/avatar", ['avatar' => $this->avatar()])->assertOk();
        $this->assertSame($this->avatar(), $member->fresh()->profile['avatar']);
        $this->patchJson("/api/scopes/{$scope->id}/contractors/{$owner->id}/avatar", ['avatar' => $this->avatar()])->assertForbidden();
        $this->assertNull($owner->fresh()->profile);
    }

    public function test_reset_clears_only_avatar(): void
    {
        [$owner, $scope, $member] = $this->workspace();
        $member->update(['profile' => ['bio' => 'Keep me', 'avatar' => $this->avatar()]]);
        $this->actingAs($member)->withHeaders(self::HEADERS)->patchJson("/api/scopes/{$scope->id}/contractors/{$member->id}/avatar", ['avatar' => null])->assertOk()->assertJsonPath('data.avatar', null);
        $this->assertSame(['bio' => 'Keep me', 'avatar' => null], $member->fresh()->profile);
    }

    #[TestWith(['preset', '../../.env'])]
    #[TestWith(['preset', 'Anima_99999_.png'])]
    #[TestWith(['crop', ['x' => 50, 'y' => 50, 'zoom' => 4]])]
    #[TestWith(['crop', ['x' => -1, 'y' => 50, 'zoom' => 1]])]
    #[TestWith(['crop', ['x' => 50, 'zoom' => 1]])]
    public function test_invalid_selection_returns_422_without_changes(string $key, mixed $value): void
    {
        [$owner, $scope, $member] = $this->workspace();
        $avatar = $this->avatar();
        $avatar[$key] = $value;
        $this->actingAs($member)->withHeaders(self::HEADERS)->patchJson("/api/scopes/{$scope->id}/contractors/{$member->id}/avatar", ['avatar' => $avatar])->assertUnprocessable();
        $this->assertSame(['bio' => 'Keep me'], $member->fresh()->profile);
    }

    public function test_guest_is_401_and_outsider_is_403_and_foreign_target_is_404(): void
    {
        [$owner, $scope, $member] = $this->workspace();
        $url = "/api/scopes/{$scope->id}/contractors/{$member->id}/avatar";
        $this->withHeaders(self::HEADERS)->patchJson($url, ['avatar' => $this->avatar()])->assertUnauthorized();
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->patchJson($url, ['avatar' => $this->avatar()])->assertForbidden();
        $this->actingAs($owner)->patchJson("/api/scopes/{$scope->id}/contractors/{$outsider->id}/avatar", ['avatar' => $this->avatar()])->assertNotFound();
        $this->assertSame(['bio' => 'Keep me'], $member->fresh()->profile);
    }

    public function test_profile_update_cannot_replace_or_erase_avatar(): void
    {
        [$owner, $scope, $member] = $this->workspace();
        $member->update(['profile' => ['avatar' => $this->avatar()]]);
        $this->actingAs($owner)->withHeaders(self::HEADERS)->patchJson("/api/scopes/{$scope->id}/contractors/{$member->id}", ['profile' => ['avatar' => ['preset' => 'Anima_00029_.png']]])->assertUnprocessable();
        $this->patchJson("/api/scopes/{$scope->id}/contractors/{$member->id}", ['profile' => null])->assertOk();
        $this->assertSame($this->avatar(), $member->fresh()->profile['avatar']);
    }

    public function test_member_can_find_self_without_receiving_other_users_or_management_permissions(): void
    {
        [$owner, $scope, $member] = $this->workspace();
        $scope->members()->where('user_id', $member->id)->update(['role' => 'observer']);
        $this->actingAs($member)->withHeaders(self::HEADERS)->getJson("/api/scopes/{$scope->id}/contractors?include_self=1")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $member->id)->assertJsonPath('data.0.can_change_avatar', true)->assertJsonPath('data.0.can_manage', false);
        $this->getJson("/api/scopes/{$scope->id}/contractors/options?include_self=1")->assertOk()->assertJsonPath('data.can_manage_all', false)->assertJsonPath('data.types', []);
    }

    public function test_member_can_load_avatar_catalog_for_their_scope(): void
    {
        [$owner, $scope, $member] = $this->workspace();
        $this->actingAs($member)->withHeaders(self::HEADERS)->getJson("/api/scopes/{$scope->id}/avatars")
            ->assertOk()->assertJsonPath('data.0', 'Anima_00024_.png');
    }

    public function test_uploaded_photo_must_belong_only_to_target_user(): void
    {
        [$owner, $scope, $member] = $this->workspace();
        $file = FilerFile::query()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'uploaded_by' => $owner->id, 'name' => 'avatar.webp', 'category' => 'user', 'visibility' => 'scope', 'disk' => 'filer', 'path' => 'test/avatar', 'mime' => 'image/webp', 'size' => 100, 'sha256' => str_repeat('a', 64)]);
        $file->attachments()->create(['subject_type' => 'user', 'subject_id' => $member->id]);
        $this->actingAs($member)->withHeaders(self::HEADERS)->patchJson("/api/scopes/{$scope->id}/contractors/{$member->id}/avatar", ['avatar' => ['file_id' => $file->id]])->assertOk();
        $this->assertSame($file->id, $member->fresh()->profile['avatar']['file_id']);
        $this->actingAs($owner)->patchJson("/api/scopes/{$scope->id}/contractors/{$owner->id}/avatar", ['avatar' => ['file_id' => $file->id]])->assertUnprocessable();
        $admin = User::factory()->create();
        $scope->members()->create(['user_id' => $admin->id, 'role' => 'admin', 'joined_at' => now()]);
        $this->actingAs($admin)->postJson("/api/scopes/{$scope->id}/files/{$file->id}/feature", ['subject_type' => 'user', 'subject_id' => $member->id, 'enabled' => false])->assertForbidden();
        $this->assertSame($file->id, $member->fresh()->profile['avatar']['file_id']);
    }
}
