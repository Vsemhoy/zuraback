<?php

namespace Tests\Feature;

use App\Models\Scope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScopeAppearanceTest extends TestCase
{
    use RefreshDatabase;

    private const THEME = ['mode' => 'gradient', 'start_color' => '#e0d1fa', 'end_color' => '#c9e7ff', 'start_opacity' => 65, 'end_opacity' => 90, 'angle' => 110];

    private function workspace(): array
    {
        $owner = User::factory()->create();
        $scope = Scope::factory()->create(['owner_id' => $owner->id, 'settings' => ['kpi' => ['target' => 75]]]);
        $this->actingAs($owner)->withHeaders(['Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-App-Request' => 'Zuratax']);

        return [$owner, $scope, "/api/scopes/{$scope->id}/appearance"];
    }

    public function test_owner_saves_shared_header_theme_without_replacing_other_settings(): void
    {
        [$owner, $scope, $url] = $this->workspace();

        $this->patchJson($url, ['header_theme' => self::THEME, 'owner_id' => 'not-allowed', 'settings' => ['kpi' => []]])
            ->assertOk()->assertJsonPath('data.settings.header_theme', self::THEME)->assertJsonPath('data.can_manage_appearance', true);

        $this->assertSame(['kpi' => ['target' => 75], 'header_theme' => self::THEME], $scope->fresh()->settings);
        $this->assertSame($owner->id, $scope->fresh()->owner_id);
        $this->getJson('/api/scopes')->assertOk()->assertJsonPath('data.0.settings.header_theme', self::THEME)->assertJsonPath('data.0.can_manage_appearance', true);
    }

    public function test_admin_can_save_theme_and_members_can_read_the_same_theme(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $scope->members()->create(['user_id' => $admin->id, 'role' => 'admin']);
        $scope->members()->create(['user_id' => $member->id, 'role' => 'member']);

        $theme = [...self::THEME, 'blur_px' => 24];
        $this->actingAs($admin)->patchJson($url, ['header_theme' => $theme])->assertOk()->assertJsonPath('data.can_manage_appearance', true);

        $this->assertSame($theme, $scope->fresh()->settings['header_theme']);
        $this->actingAs($member)->getJson('/api/scopes')->assertOk()->assertJsonPath('data.0.settings.header_theme', $theme)->assertJsonPath('data.0.can_manage_appearance', false);
        $this->getJson("/api/scopes/{$scope->id}")->assertOk()->assertJsonPath('data.settings.header_theme', $theme)->assertJsonPath('data.can_manage_appearance', false);
    }

    public function test_solid_theme_accepts_zero_opacity_and_preserves_second_color(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $theme = [...self::THEME, 'mode' => 'solid', 'start_opacity' => 0, 'end_opacity' => 100, 'angle' => 360];

        $this->patchJson($url, ['header_theme' => $theme])->assertOk()->assertJsonPath('data.settings.header_theme', $theme);

        $this->assertSame($theme, $scope->fresh()->settings['header_theme']);
    }

    #[DataProvider('invalidThemes')]
    public function test_invalid_theme_returns_422_and_preserves_settings(array $changes, string $field): void
    {
        [$owner, $scope, $url] = $this->workspace();

        $this->patchJson($url, ['header_theme' => [...self::THEME, ...$changes]])->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertSame(['kpi' => ['target' => 75]], $scope->fresh()->settings);
    }

    public static function invalidThemes(): array
    {
        return [
            'unknown mode' => [['mode' => 'url'], 'header_theme.mode'],
            'css payload' => [['start_color' => 'url(https://example.invalid)'], 'header_theme.start_color'],
            'rgba string' => [['end_color' => 'rgba(1,2,3,.4)'], 'header_theme.end_color'],
            'negative opacity' => [['start_opacity' => -1], 'header_theme.start_opacity'],
            'large opacity' => [['end_opacity' => 101], 'header_theme.end_opacity'],
            'fractional opacity' => [['end_opacity' => 0.5], 'header_theme.end_opacity'],
            'negative angle' => [['angle' => -1], 'header_theme.angle'],
            'large angle' => [['angle' => 361], 'header_theme.angle'],
            'unexpected css' => [['background' => 'url(https://example.invalid)'], 'header_theme'],
            'negative blur' => [['blur_px' => -1], 'header_theme.blur_px'],
            'large blur' => [['blur_px' => 41], 'header_theme.blur_px'],
            'fractional blur' => [['blur_px' => 0.5], 'header_theme.blur_px'],
            'null blur' => [['blur_px' => null], 'header_theme.blur_px'],
            'css blur' => [['blur_px' => '16px;url(https://example.invalid)'], 'header_theme.blur_px'],
        ];
    }

    #[DataProvider('blurBoundaries')]
    public function test_blur_boundaries_are_saved_and_returned(int $blur): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $theme = [...self::THEME, 'blur_px' => $blur];

        $this->patchJson($url, ['header_theme' => $theme])->assertOk()->assertJsonPath('data.settings.header_theme', $theme);

        $this->assertSame($theme, $scope->fresh()->settings['header_theme']);
        $this->assertSame(75, $scope->fresh()->settings['kpi']['target']);
    }

    public static function blurBoundaries(): array
    {
        return ['disabled' => [0], 'maximum' => [40]];
    }

    public function test_legacy_client_preserves_existing_blur_when_updating_colors(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $scope->update(['settings' => ['kpi' => ['target' => 75], 'header_theme' => [...self::THEME, 'blur_px' => 0]]]);
        $theme = [...self::THEME, 'start_color' => '#123456'];

        $this->patchJson($url, ['header_theme' => $theme])->assertOk()->assertJsonPath('data.settings.header_theme', [...$theme, 'blur_px' => 0]);

        $this->assertSame(['kpi' => ['target' => 75], 'header_theme' => [...$theme, 'blur_px' => 0]], $scope->fresh()->settings);
    }

    public function test_missing_theme_returns_422_without_clearing_existing_settings(): void
    {
        [$owner, $scope, $url] = $this->workspace();

        $this->patchJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('header_theme');

        $this->assertSame(['kpi' => ['target' => 75]], $scope->fresh()->settings);
    }

    #[DataProvider('deniedRoles')]
    public function test_non_admin_roles_receive_403_without_changing_scope(string $role, bool $active): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $member = User::factory()->create();
        $scope->members()->create(['user_id' => $member->id, 'role' => $role, 'is_active' => $active]);

        $this->actingAs($member)->patchJson($url, ['header_theme' => self::THEME])->assertForbidden();

        $this->assertSame(['kpi' => ['target' => 75]], $scope->fresh()->settings);
    }

    public static function deniedRoles(): array
    {
        return ['member' => ['member', true], 'observer' => ['observer', true], 'inactive admin' => ['admin', false]];
    }

    public function test_scope_theme_cannot_be_changed_by_owner_of_another_scope(): void
    {
        [$owner, $scope] = $this->workspace();
        $foreign = Scope::factory()->create();

        $this->patchJson("/api/scopes/{$foreign->id}/appearance", ['header_theme' => self::THEME])->assertForbidden();

        $this->assertNull($foreign->fresh()->settings);
        $this->assertSame(['kpi' => ['target' => 75]], $scope->fresh()->settings);
    }

    public function test_unauthenticated_request_returns_401_without_changing_settings(): void
    {
        $scope = Scope::factory()->create();

        $this->withHeader('X-App-Request', 'Zuratax')->patchJson("/api/scopes/{$scope->id}/appearance", ['header_theme' => self::THEME])->assertUnauthorized();

        $this->assertNull($scope->fresh()->settings);
    }
}
