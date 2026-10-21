<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Scope;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DepartmentIdentityTest extends TestCase
{
    use RefreshDatabase;

    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M2 2h20v20H2z"/></svg>';

    private function workspace(): array
    {
        $owner = User::factory()->create();
        $scope = Scope::factory()->create(['owner_id' => $owner->id]);
        $this->actingAs($owner)->withHeaders(['Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-App-Request' => 'Zuratax']);

        return [$owner, $scope, "/api/scopes/{$scope->id}/departments"];
    }

    public function test_department_stores_normalized_code_and_svg_without_losing_members(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $member = User::factory()->create();
        $scope->members()->create(['user_id' => $member->id, 'role' => 'member']);

        $id = $this->postJson($url, ['name' => 'Administration', 'code' => ' adm ', 'icon_svg' => self::SVG, 'user_ids' => [$member->id]])
            ->assertOk()->assertJsonPath('data.code', 'ADM')->assertJsonPath('data.icon_svg', self::SVG)->json('data.id');

        $this->assertDatabaseHas('departments', ['id' => $id, 'scope_id' => $scope->id, 'code' => 'ADM', 'icon_svg' => self::SVG]);
        $this->assertDatabaseHas('scope_members', ['user_id' => $member->id, 'department_id' => $id]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.departments.0.code', 'ADM')->assertJsonPath('data.departments.0.icon_svg', self::SVG);
    }

    #[DataProvider('validCodes')]
    public function test_codes_support_three_unicode_letters_or_digits(string $code, string $expected): void
    {
        [$owner, $scope, $url] = $this->workspace();

        $id = $this->postJson($url, ['name' => 'Department', 'code' => $code])->assertOk()->assertJsonPath('data.code', $expected)->json('data.id');

        $this->assertDatabaseHas('departments', ['id' => $id, 'code' => $expected]);
    }

    public static function validCodes(): array
    {
        return ['cyrillic' => ['итд', 'ИТД'], 'latin' => ['r2d', 'R2D'], 'chinese' => ['仓储部', '仓储部'], 'zeroes' => ['000', '000']];
    }

    #[DataProvider('invalidCodes')]
    public function test_invalid_codes_return_422_without_creating_department(mixed $code): void
    {
        [$owner, $scope, $url] = $this->workspace();

        $this->postJson($url, ['name' => 'Invalid code', 'code' => $code])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->assertDatabaseCount('departments', 0);
    }

    public static function invalidCodes(): array
    {
        return ['short' => ['IT'], 'long' => ['TEAM'], 'punctuation' => ['A-B'], 'whitespace' => ['A B'], 'single zero' => ['0'], 'array' => [['ADM']], 'number' => [123], 'emoji' => ['😀😀😀']];
    }

    public function test_codes_are_unique_within_scope_and_update_can_keep_own_code(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $department = Department::factory()->create(['scope_id' => $scope->id, 'code' => 'ADM']);

        $this->postJson($url, ['name' => 'Duplicate', 'code' => 'adm'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->putJson($url.'/'.$department->id, ['name' => 'Renamed', 'code' => 'adm'])->assertOk()->assertJsonPath('data.code', 'ADM');

        $this->assertDatabaseCount('departments', 1);
        $this->assertDatabaseHas('departments', ['id' => $department->id, 'name' => 'Renamed', 'code' => 'ADM']);
    }

    public function test_codes_can_be_reused_in_other_scopes(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        Department::factory()->create(['code' => 'ADM']);

        $id = $this->postJson($url, ['name' => 'Our department', 'code' => 'ADM'])->assertOk()->json('data.id');

        $this->assertDatabaseHas('departments', ['id' => $id, 'scope_id' => $scope->id, 'code' => 'ADM']);
    }

    public function test_legacy_requests_preserve_identity_and_null_clears_it(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $department = Department::factory()->create(['scope_id' => $scope->id, 'code' => 'ADM', 'icon_svg' => self::SVG]);

        $this->putJson($url.'/'.$department->id, ['name' => 'Legacy update'])->assertOk()->assertJsonPath('data.code', 'ADM')->assertJsonPath('data.icon_svg', self::SVG);
        $this->putJson($url.'/'.$department->id, ['name' => 'Cleared', 'code' => null, 'icon_svg' => null])->assertOk()->assertJsonPath('data.code', null)->assertJsonPath('data.icon_svg', null);
        $id = $this->postJson($url, ['name' => 'Legacy creation'])->assertOk()->json('data.id');

        $this->assertDatabaseHas('departments', ['id' => $department->id, 'code' => null, 'icon_svg' => null]);
        $this->assertDatabaseHas('departments', ['id' => $id, 'code' => null, 'icon_svg' => null]);
    }

    #[DataProvider('unsafeIcons')]
    public function test_unsafe_svg_returns_422_and_preserves_stored_icon(string $svg): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $department = Department::factory()->create(['scope_id' => $scope->id, 'icon_svg' => self::SVG]);

        $this->putJson($url.'/'.$department->id, ['name' => 'Must not save', 'icon_svg' => $svg])->assertUnprocessable()->assertJsonValidationErrors('icon_svg');

        $this->assertDatabaseHas('departments', ['id' => $department->id, 'name' => $department->name, 'icon_svg' => self::SVG]);
    }

    public static function unsafeIcons(): array
    {
        $prefix = '<svg xmlns="http://www.w3.org/2000/svg">';

        return [
            'malformed' => ['<svg>'],
            'html root' => ['<html/>'],
            'missing namespace' => ['<svg/>'],
            'script' => [$prefix.'<script>alert(1)</script></svg>'],
            'handler' => ['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'],
            'foreign object' => [$prefix.'<foreignObject><div/></foreignObject></svg>'],
            'external href' => [$prefix.'<image href="https://example.invalid/image.png"/></svg>'],
            'data href' => [$prefix.'<image href="data:image/png;base64,AAAA"/></svg>'],
            'external paint' => [$prefix.'<path fill="url(https://example.invalid/paint)"/></svg>'],
            'external style' => [$prefix.'<style>@import "https://example.invalid/a.css";</style></svg>'],
            'processing instruction' => ['<?xml-stylesheet href="https://example.invalid/a.css"?>'.$prefix.'</svg>'],
            'doctype' => ['<!DOCTYPE svg SYSTEM "https://example.invalid/svg.dtd">'.$prefix.'</svg>'],
            'entity' => ['<!DOCTYPE svg [<!ENTITY secret SYSTEM "file:///not-a-real-secret">]>'.$prefix.'<text>&secret;</text></svg>'],
            'animation' => [$prefix.'<animate attributeName="href"/></svg>'],
            'utf8 size' => [$prefix.'<text>'.str_repeat('я', 17000).'</text></svg>'],
        ];
    }

    public function test_static_svg_can_use_local_definitions_and_gradients(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><defs><linearGradient id="paint"><stop offset="0" stop-color="red"/></linearGradient><path id="shape" d="M0 0h10v10z"/></defs><use href="#shape" fill="url(#paint)"/></svg>';

        $id = $this->postJson($url, ['name' => 'Gradient icon', 'icon_svg' => $svg])->assertOk()->assertJsonPath('data.icon_svg', $svg)->json('data.id');

        $this->assertDatabaseHas('departments', ['id' => $id, 'icon_svg' => $svg]);
    }

    public function test_members_cannot_change_icons_and_foreign_departments_stay_isolated(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $foreign = Department::factory()->create(['code' => 'ADM']);
        $member = User::factory()->create();
        $scope->members()->create(['user_id' => $member->id, 'role' => 'member']);

        $this->putJson($url.'/'.$foreign->id, ['name' => 'Foreign update', 'code' => 'WHS', 'icon_svg' => self::SVG])->assertNotFound();
        $this->actingAs($member)->postJson($url, ['name' => 'Denied', 'code' => 'WHS', 'icon_svg' => self::SVG])->assertForbidden();

        $this->assertDatabaseHas('departments', ['id' => $foreign->id, 'name' => $foreign->name, 'code' => 'ADM', 'icon_svg' => null]);
        $this->assertDatabaseMissing('departments', ['scope_id' => $scope->id]);
    }

    public function test_calendar_tasks_tails_and_unscheduled_tasks_include_department_identity(): void
    {
        [$owner, $scope] = $this->workspace();
        $department = Department::factory()->create(['scope_id' => $scope->id, 'code' => 'ADM', 'icon_svg' => self::SVG]);
        $task = Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'department_id' => $department->id, 'due_at' => '2026-10-08']);
        $task->plannerTails()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'planned_on' => '2026-10-09']);
        Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'department_id' => $department->id]);

        $this->getJson("/api/scopes/{$scope->id}/planner?from=2026-10-01&to=2026-10-31")->assertOk()
            ->assertJsonPath('data.tasks.0.department.code', 'ADM')->assertJsonPath('data.tasks.0.department.icon_svg', self::SVG)
            ->assertJsonPath('data.tails.0.task.department.code', 'ADM')->assertJsonPath('data.tails.0.task.department.icon_svg', self::SVG)
            ->assertJsonPath('data.unscheduled.0.department.code', 'ADM')->assertJsonPath('data.unscheduled.0.department.icon_svg', self::SVG);
    }
}
