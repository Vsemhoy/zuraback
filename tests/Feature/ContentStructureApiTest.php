<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\ContentCategory;
use App\Models\EventSection;
use App\Models\Project;
use App\Models\Scope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ContentStructureApiTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): array
    {
        $owner = User::factory()->create();
        $scope = Scope::factory()->create(['owner_id' => $owner->id]);
        $this->actingAs($owner)->withHeaders(['Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-App-Request' => 'Zuratax']);

        return [$owner, $scope, "/api/scopes/{$scope->id}"];
    }

    public function test_one_catalog_is_shared_by_eventor_and_lightbook_without_changing_project_context(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $folder = $this->postJson($url.'/event-sections', ['name' => 'Инструкции'])->assertCreated()->json('data');
        $category = $this->postJson($url.'/content-structure/lightbook/categories', ['name' => 'Регламент', 'color' => '#123456'])->assertCreated()->json('data');
        $project = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);

        $event = $this->postJson($url.'/events', ['title' => 'Событие', 'section_id' => $folder['id'], 'category_id' => $category['id'], 'project_id' => $project->id, 'content' => 'Не потерять'])->assertCreated()->json('data');
        $book = $this->postJson($url.'/lightbooks', ['title' => 'Регламент', 'section_id' => $folder['id'], 'category_id' => $category['id']])->assertCreated()->json('data');

        $this->getJson($url.'/content-structure/eventor')->assertOk()->assertJsonCount(1, 'data.sections')->assertJsonPath('data.categories.0.id', $category['id']);
        $this->getJson($url.'/content-structure/lightbook')->assertOk()->assertJsonPath('data.sections.0.id', $folder['id']);
        $this->assertDatabaseHas('events', ['id' => $event['id'], 'section_id' => $folder['id'], 'category_id' => $category['id'], 'project_id' => $project->id]);
        $this->assertDatabaseHas('books', ['id' => $book['id'], 'section_id' => $folder['id'], 'category_id' => $category['id'], 'project_id' => null]);
        $this->assertDatabaseHas('book_pages', ['id' => $book['page_id'], 'book_id' => $book['id']]);
        $this->patchJson($url.'/events/'.$event['id'], ['section_id' => null, 'category_id' => null])->assertOk()->assertJsonPath('data.project_id', $project->id)->assertJsonPath('data.content', 'Не потерять');
        $this->patchJson($url.'/lightbooks/'.$book['id'], ['project_id' => $project->id])->assertOk()->assertJsonPath('data.section_id', $folder['id']);
        $this->patchJson($url.'/lightbooks/'.$book['id'], ['project_id' => null])->assertOk()->assertJsonPath('data.project_id', null)->assertJsonPath('data.category_id', $category['id']);
    }

    public function test_nested_folder_filters_include_descendants_and_none_filters_are_independent(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $root = EventSection::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $child = EventSection::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'parent_id' => $root->id]);
        $category = ContentCategory::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        foreach ([$root->id, $child->id, null] as $folderId) {
            $payload = ['title' => 'Запись', 'section_id' => $folderId, 'category_id' => $folderId ? $category->id : null];
            $this->postJson($url.'/events', [...$payload, 'starts_at' => '2026-10-10 09:00:00'])->assertCreated();
            $this->postJson($url.'/lightbooks', $payload)->assertCreated();
        }

        foreach (['events', 'lightbooks'] as $module) {
            $this->getJson($url."/{$module}?section_id={$root->id}&category_id={$category->id}&project_id=none")->assertOk()->assertJsonCount(2, 'data');
            $this->getJson($url."/{$module}?section_id=none&category_id=none")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.section_id', null);
        }
        $this->getJson($url."/events/calendar?from=2026-10-01&until=2026-10-31&section_id={$root->id}&category_id={$category->id}")->assertOk()->assertJsonCount(2, 'data');
    }

    public static function modulesAndFields(): array
    {
        return ['event_section' => ['events', 'section_id'], 'event_category' => ['events', 'category_id'],
            'book_section' => ['lightbooks', 'section_id'], 'book_category' => ['lightbooks', 'category_id']];
    }

    #[DataProvider('modulesAndFields')]
    public function test_cross_scope_or_archived_assignment_is_rejected_422_without_content_changes(string $module, string $field): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $class = $field === 'section_id' ? EventSection::class : ContentCategory::class;
        $other = $class::factory()->create();
        $archived = $class::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'is_archived' => true]);
        $entry = $this->postJson($url.'/'.$module, ['title' => 'Не менять'])->assertCreated()->json('data');

        foreach ([$other->id, $archived->id] as $bad) {
            $this->patchJson($url."/{$module}/{$entry['id']}", ['title' => 'Неверное изменение', $field => $bad])->assertUnprocessable()->assertJsonValidationErrors($field);
        }

        $this->assertDatabaseHas($module === 'events' ? 'events' : 'books', ['id' => $entry['id'], 'title' => 'Не менять', $field => null]);
    }

    public function test_cycles_cross_scope_parents_and_stale_revisions_are_refused(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $root = EventSection::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $child = EventSection::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'parent_id' => $root->id]);
        $other = EventSection::factory()->create();
        $base = $url.'/content-structure/eventor/sections';

        $this->patchJson($base.'/'.$root->id, ['parent_id' => $child->id, 'revision' => 1])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->patchJson($base.'/'.$root->id, ['parent_id' => $other->id, 'revision' => 1])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->patchJson($base.'/'.$child->id, ['parent_id' => null, 'sort_order' => 7, 'revision' => 1])->assertOk()->assertJsonPath('data.revision', 2);
        $this->patchJson($base.'/'.$child->id, ['name' => 'Старая правка', 'revision' => 1])->assertConflict();
        $this->assertDatabaseHas('event_sections', ['id' => $child->id, 'parent_id' => null, 'sort_order' => 7, 'revision' => 2]);
        $this->assertDatabaseHas('activity_logs', ['scope_id' => $scope->id, 'action' => 'content.sections.updated']);
    }

    public function test_filled_catalog_entries_cannot_be_deleted_but_can_be_archived_without_losing_records(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $folder = EventSection::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $category = ContentCategory::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $event = $this->postJson($url.'/events', ['title' => 'История', 'section_id' => $folder->id, 'category_id' => $category->id])->assertCreated()->json('data');
        $base = $url.'/content-structure/eventor';

        $this->deleteJson($base.'/sections/'.$folder->id, ['revision' => 1])->assertUnprocessable()->assertJsonValidationErrors('structure');
        $this->deleteJson($base.'/categories/'.$category->id, ['revision' => 1])->assertUnprocessable();
        $this->patchJson($base.'/sections/'.$folder->id, ['is_archived' => true, 'revision' => 1])->assertOk()->assertJsonPath('data.is_archived', true);
        $this->patchJson($url.'/events/'.$event['id'], ['title' => 'Можно править', 'section_id' => $folder->id])->assertOk();
        $this->postJson($url.'/events', ['title' => 'Новая', 'section_id' => $folder->id])->assertUnprocessable();
        $this->getJson($url.'/events?section_id='.$folder->id)->assertOk()->assertJsonPath('data.0.title', 'Можно править');
        $this->assertDatabaseHas('events', ['id' => $event['id'], 'section_id' => $folder->id, 'deleted_at' => null]);
    }

    public function test_empty_entries_can_be_deleted_and_soft_deleted_records_still_protect_references(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $folder = EventSection::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $empty = ContentCategory::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $book = Book::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'kind' => 'lightbook', 'section_id' => $folder->id]);
        $book->delete();

        $this->deleteJson($url.'/content-structure/lightbook/sections/'.$folder->id, ['revision' => 1])->assertUnprocessable();
        $this->deleteJson($url.'/content-structure/lightbook/categories/'.$empty->id, ['revision' => 1])->assertNoContent();

        $this->assertModelExists($folder);
        $this->assertModelMissing($empty);
        $this->assertSoftDeleted($book);
    }

    public function test_private_folder_names_and_other_creators_management_are_not_exposed(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $private = EventSection::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'name' => 'Скрытая папка']);
        $child = EventSection::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'parent_id' => $private->id, 'visibility' => 'scope']);
        $shared = EventSection::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'scope']);
        $member = User::factory()->create();
        $scope->members()->create(['user_id' => $member->id, 'role' => 'member', 'project_access_mode' => 'all', 'book_access_mode' => 'all']);
        $this->actingAs($member);

        $this->getJson($url.'/content-structure/eventor')->assertOk()->assertJsonCount(1, 'data.sections')->assertJsonPath('data.sections.0.id', $shared->id)->assertJsonPath('data.sections.0.can_manage', false);
        $this->getJson($url.'/event-sections')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson($url.'/events', ['title' => 'Ссылка', 'section_id' => $private->id])->assertUnprocessable();
        $this->getJson($url.'/events?section_id='.$child->id)->assertNotFound();
        $this->patchJson($url.'/content-structure/eventor/sections/'.$shared->id, ['name' => 'Не моё', 'revision' => 1])->assertForbidden();
    }

    public function test_common_section_does_not_bypass_record_privacy_or_restricted_project_access(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $folder = EventSection::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'scope']);
        $privateProject = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'private', 'show_in_eventor' => true]);
        $event = $this->postJson($url.'/events', ['title' => 'Секрет проекта', 'section_id' => $folder->id, 'project_id' => $privateProject->id, 'visibility' => 'scope'])->assertCreated()->json('data');
        $book = $this->postJson($url.'/lightbooks', ['title' => 'Секретный документ', 'section_id' => $folder->id, 'visibility' => 'private'])->assertCreated()->json('data');
        $member = User::factory()->create();
        $scope->members()->create(['user_id' => $member->id, 'role' => 'observer', 'project_access_mode' => 'restricted', 'book_access_mode' => 'projects', 'permissions' => ['allow' => ['book.view']]]);
        $this->actingAs($member);

        $this->getJson($url.'/events?section_id='.$folder->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($url.'/lightbooks?section_id='.$folder->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($url.'/events/'.$event['id'])->assertNotFound();
        $this->getJson($url.'/lightbooks/'.$book['id'])->assertForbidden();
    }

    public function test_read_only_agents_can_discover_but_cannot_create_or_cross_module_token_boundaries(): void
    {
        [$owner, $scope] = $this->workspace();
        $agent = User::factory()->agent()->create(['created_by' => $owner->id]);
        $scope->members()->create(['user_id' => $agent->id, 'role' => 'admin', 'project_access_mode' => 'all', 'book_access_mode' => 'all']);
        Sanctum::actingAs($agent, ['task.view']);
        $base = "/api/agent/scopes/{$scope->id}";

        $this->getJson($base.'/content-structure/eventor')->assertOk()->assertJsonPath('data.can_create', false);
        $this->postJson($base.'/content-structure/eventor/sections', ['name' => 'Запрещено'])->assertForbidden();
        $this->getJson($base.'/content-structure/lightbook')->assertForbidden();
        $this->getJson($base.'/events?section_id=none')->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('event_sections', 0);
        Sanctum::actingAs($agent, ['book.view', 'book.create', 'book.update']);
        $category = $this->postJson($base.'/content-structure/lightbook/categories', ['name' => 'Агентская категория'])->assertCreated()->json('data');
        $this->postJson($base.'/lightbooks', ['title' => 'Документ агента', 'category_id' => $category['id']])->assertCreated()->assertJsonPath('data.category_id', $category['id']);
        $this->getJson($base.'/events')->assertForbidden();
    }

    public function test_catalog_write_requires_capability_also_on_legacy_alias_and_disabled_modules_stay_disabled(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $member = User::factory()->create();
        $scope->members()->create(['user_id' => $member->id, 'role' => 'observer']);
        $this->actingAs($member)->postJson($url.'/event-sections', ['name' => 'Нет права'])->assertForbidden();
        $this->actingAs($owner);
        $scope->update(['settings' => ['modules' => ['enabled' => ['eventor' => false]]]]);

        $this->getJson($url.'/content-structure/eventor')->assertForbidden();
        $this->getJson($url.'/content-structure/lightbook')->assertOk();
        $this->assertDatabaseCount('event_sections', 0);
    }

    public function test_structure_validation_and_cross_scope_update_do_not_mutate_data(): void
    {
        [, , $url] = $this->workspace();
        $foreign = EventSection::factory()->create();

        $this->patchJson($url.'/content-structure/eventor/sections/'.$foreign->id, ['name' => 'Нельзя', 'revision' => 1])->assertNotFound();
        $this->postJson($url.'/content-structure/eventor/categories', ['name' => 'Категория', 'parent_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->postJson($url.'/content-structure/eventor/categories', ['name' => 'Категория', 'color' => 'url(javascript:x)'])->assertUnprocessable()->assertJsonValidationErrors('color');
        $this->postJson($url.'/content-structure/eventor/categories', ['name' => 'Категория'])->assertCreated();
        $this->postJson($url.'/content-structure/eventor/categories', ['name' => ' категория '])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->getJson($url.'/events?section_id[]=bad')->assertUnprocessable()->assertJsonValidationErrors('section_id');
        $this->assertDatabaseCount('content_categories', 1);
        $this->assertDatabaseHas('event_sections', ['id' => $foreign->id, 'name' => $foreign->name]);
    }
}
