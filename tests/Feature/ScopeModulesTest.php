<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookBlock;
use App\Models\BookBlockGroup;
use App\Models\BookPage;
use App\Models\Scope;
use App\Models\Task;
use App\Models\User;
use App\Services\ScopeModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScopeModulesTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): array
    {
        $owner = User::factory()->create();
        $scope = Scope::factory()->create(['owner_id' => $owner->id, 'settings' => ['header_theme' => ['blur_px' => 12]]]);
        $this->actingAs($owner)->withHeaders(['Accept' => 'application/json', 'X-App-Request' => 'Zuratax']);

        return [$owner, $scope, "/api/scopes/{$scope->id}"];
    }

    public function test_existing_scope_keeps_modules_and_owner_can_save_shared_order_and_disable_task_dependents(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $task = Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $this->getJson('/api/scopes')->assertOk()->assertJsonPath('data.0.modules.enabled.tasker', true);
        $settings = ScopeModuleService::settings($scope);
        $settings['enabled']['tasker'] = false;
        $settings['order'] = array_reverse($settings['order']);
        $this->patchJson($url.'/modules', $settings)->assertOk()
            ->assertJsonPath('data.modules.enabled.tasker', false)
            ->assertJsonPath('data.modules.enabled.calendar', false)
            ->assertJsonPath('data.modules.enabled.planner', false)
            ->assertJsonPath('data.modules.enabled.kpi', false)
            ->assertJsonPath('data.modules.enabled.reporter', false)
            ->assertJsonPath('data.modules.enabled.projector', true)
            ->assertJsonPath('data.modules.enabled.equip', true)
            ->assertJsonPath('data.modules.order', $settings['order']);
        $this->assertSame(12, $scope->fresh()->settings['header_theme']['blur_px']);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'title' => $task->title]);
        $this->assertDatabaseHas('activity_logs', ['scope_id' => $scope->id, 'action' => 'scope.modules_updated']);
        $this->getJson($url.'/projects')->assertOk();
        $this->getJson($url.'/tasks')->assertForbidden()->assertJsonPath('code', 'module_disabled');
        $member = User::factory()->create();
        $scope->members()->create(['user_id' => $member->id, 'role' => 'member']);
        $this->actingAs($member)->getJson('/api/scopes')->assertOk()
            ->assertJsonPath('data.0.modules.order', $settings['order'])->assertJsonPath('data.0.can_manage_modules', false);
    }

    public function test_reenabling_tasker_preserves_explicitly_disabled_children_and_recovers_data(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $task = Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $settings = ScopeModuleService::settings($scope);
        $settings['enabled']['tasker'] = false;
        $this->patchJson($url.'/modules', $settings)->assertOk();
        $settings = ScopeModuleService::settings($scope->fresh());
        $settings['enabled']['tasker'] = true;
        $this->patchJson($url.'/modules', $settings)->assertOk()->assertJsonPath('data.modules.enabled.calendar', false);
        $this->getJson($url.'/tasks/'.$task->id)->assertOk()->assertJsonPath('data.id', $task->id);
        $settings['enabled']['calendar'] = true;
        $this->patchJson($url.'/modules', $settings)->assertOk()->assertJsonPath('data.modules.enabled.calendar', true);
    }

    #[DataProvider('nonOwners')]
    public function test_only_owner_can_change_modules_not_admin_or_other_scope_member(string $role): void
    {
        [, $scope, $url] = $this->workspace();
        $user = User::factory()->create();
        if ($role !== 'outsider') {
            $scope->members()->create(['user_id' => $user->id, 'role' => $role]);
        }
        $this->actingAs($user)->patchJson($url.'/modules', ScopeModuleService::settings($scope))->assertForbidden();
        $this->assertSame(['header_theme' => ['blur_px' => 12]], $scope->fresh()->settings);
    }

    public static function nonOwners(): array
    {
        return [['admin'], ['member'], ['observer'], ['outsider']];
    }

    public function test_an_owner_acting_as_another_person_cannot_modify_scope_configuration(): void
    {
        [, $scope, $url] = $this->workspace();
        $this->withSession(['contractor.actor_id' => 'someone'])->patchJson($url.'/modules', ScopeModuleService::settings($scope))->assertForbidden();
    }

    public function test_guest_cannot_change_scope_modules(): void
    {
        $scope = Scope::factory()->create();
        $this->withHeaders(['X-App-Request' => 'Zuratax'])->patchJson("/api/scopes/{$scope->id}/modules", ScopeModuleService::settings($scope))->assertUnauthorized();
    }

    public function test_rejects_disabling_projector_unknown_modules_duplicate_order_and_missing_settings(): void
    {
        [, $scope, $url] = $this->workspace();
        $settings = ScopeModuleService::settings($scope);
        $this->patchJson($url.'/modules', [])->assertUnprocessable()->assertJsonValidationErrors(['enabled', 'order']);
        $invalid = $settings;
        $invalid['enabled']['projector'] = false;
        $this->patchJson($url.'/modules', $invalid)->assertUnprocessable()->assertJsonValidationErrors('enabled.projector');
        $invalid = $settings;
        $invalid['enabled']['budget'] = true;
        $this->patchJson($url.'/modules', $invalid)->assertUnprocessable()->assertJsonValidationErrors('enabled');
        $invalid = $settings;
        $invalid['order'][1] = $invalid['order'][0];
        $this->patchJson($url.'/modules', $invalid)->assertUnprocessable()->assertJsonValidationErrors('order.0');
        $invalid['order'][0] = 'unknown';
        $this->patchJson($url.'/modules', $invalid)->assertUnprocessable()->assertJsonValidationErrors('order.0');
        $this->assertSame(['header_theme' => ['blur_px' => 12]], $scope->fresh()->settings);
    }

    #[DataProvider('moduleEndpoints')]
    public function test_disabled_module_blocks_session_and_agent_routes(string $module, string $path, bool $agentRoute): void
    {
        [, $scope, $url] = $this->workspace();
        $scope->update(['settings' => ['modules' => ['enabled' => [$module => false]]]]);
        $this->getJson($url.$path)->assertForbidden()->assertJsonPath('code', 'module_disabled');
        if ($agentRoute) {
            $agent = User::factory()->create(['type' => 'agent']);
            $scope->members()->create(['user_id' => $agent->id, 'role' => 'member', 'project_access_mode' => 'all']);
            Sanctum::actingAs($agent, ['*']);
            $this->getJson("/api/agent/scopes/{$scope->id}".$path)->assertForbidden()->assertJsonPath('code', 'module_disabled');
        }
    }

    public static function moduleEndpoints(): array
    {
        return [
            ['tasker', '/tasks', true], ['calendar', '/planner', false], ['planner', '/plans', true],
            ['kpi', '/kpis', false], ['reporter', '/reports/monthly', false], ['eventor', '/events', false],
            ['factor', '/facts', false], ['darklog', '/darklogs', true], ['lore', '/lore', true],
            ['booker', '/books', true], ['content', '/diagrams', true], ['equip', '/equip', false],
        ];
    }

    public function test_settings_do_not_affect_other_scopes_and_hidden_tasks_leave_search_dashboard_and_agent_feed(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $other = Scope::factory()->create(['owner_id' => $owner->id]);
        $agent = User::factory()->create(['type' => 'agent']);
        foreach ([$scope, $other] as $workspace) {
            $workspace->members()->create(['user_id' => $agent->id, 'role' => 'member', 'project_access_mode' => 'all']);
        }
        $task = Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'title' => 'Hidden Needle', 'delegated_agent_id' => $agent->id, 'is_agent_delegatable' => true]);
        $otherTask = Task::factory()->create(['scope_id' => $other->id, 'created_by' => $owner->id, 'delegated_agent_id' => $agent->id, 'is_agent_delegatable' => true]);
        $scope->update(['settings' => ['modules' => ['enabled' => ['tasker' => false]]]]);
        $this->getJson($url.'/search?q=Needle')->assertOk()->assertJsonMissing(['title' => $task->title]);
        $this->getJson($url.'/dashboard')->assertOk()->assertJsonPath('data.my_tasks', [])->assertJsonPath('data.recent.tasks', [])->assertJsonPath('data.kpi', null);
        $this->getJson("/api/scopes/{$other->id}/tasks/{$otherTask->id}")->assertOk();
        Sanctum::actingAs($agent, ['task.view']);
        $this->getJson('/api/agent/tasks')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $otherTask->id);
    }

    public function test_booker_and_lightbook_can_be_disabled_independently_and_content_nodes_respect_it(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $book = Book::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $lightbook = Book::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'kind' => 'lightbook']);
        $scope->update(['settings' => ['modules' => ['enabled' => ['content' => false]]]]);
        $this->getJson($url.'/books/'.$book->id)->assertOk();
        $this->getJson($url.'/content-nodes/lightbook/'.$lightbook->id)->assertNotFound();
        $scope->update(['settings' => ['modules' => ['enabled' => ['booker' => false]]]]);
        $this->getJson($url.'/content-nodes/lightbook/'.$lightbook->id)->assertOk();
    }

    public function test_lightbook_children_follow_content_instead_of_booker(): void
    {
        $scope = new Scope(['settings' => ['modules' => ['enabled' => ['booker' => false]]]]);
        $book = new Book(['kind' => 'lightbook']);
        $page = (new BookPage)->setRelation('book', $book);
        $group = (new BookBlockGroup)->setRelation('page', $page);
        $block = (new BookBlock)->setRelation('group', $group);
        foreach ([$page, $group, $block] as $record) {
            $this->assertTrue(ScopeModuleService::entityEnabled($scope, $record));
        }
        $scope->settings = ['modules' => ['enabled' => ['content' => false]]];
        foreach ([$page, $group, $block] as $record) {
            $this->assertFalse(ScopeModuleService::entityEnabled($scope, $record));
        }
    }

    public function test_links_follow_the_parent_module_for_lightbook_pages(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $task = Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $book = Book::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'kind' => 'lightbook']);
        $page = BookPage::factory()->create(['book_id' => $book->id, 'created_by' => $owner->id]);
        $payload = ['source_type' => 'book_page', 'source_id' => $page->id, 'target_type' => 'task', 'target_id' => $task->id];
        $scope->update(['settings' => ['modules' => ['enabled' => ['booker' => false]]]]);
        $scope->entityLinks()->create([...$payload, 'created_by' => $owner->id]);
        $this->getJson($url.'/links')->assertOk()->assertJsonCount(1, 'data');
        $scope->update(['settings' => ['modules' => ['enabled' => ['content' => false]]]]);
        $this->getJson($url.'/links')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson($url.'/links', [...$payload, 'source_type' => 'book', 'source_id' => $book->id])->assertForbidden();
    }
}
