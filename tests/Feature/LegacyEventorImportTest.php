<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Event;
use App\Models\EventType;
use App\Models\LegacyImportRecord;
use App\Models\Project;
use App\Models\Scope;
use App\Models\User;
use App\Services\LegacyEventorImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as Events;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class LegacyEventorImportTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $owner = User::factory()->create();
        $scope = Scope::factory()->create(['owner_id' => $owner->id, 'is_private' => true]);
        $sourceOwner = Str::lower((string) Str::ulid());
        $row = fn (array $extra): array => ['id' => Str::lower((string) Str::ulid()), 'user_id' => $sourceOwner,
            'created_at' => '2025-01-01 10:00:00', 'updated_at' => '2025-02-01 11:00:00', ...$extra];
        $section = $row(['name' => 'Дела', 'color' => '#aabbcc', 'bgcolor' => '#ddeeff', 'sort_order' => 3, 'is_archived' => 0]);
        $category = $row(['name' => 'Личное', 'sort_order' => 2, 'is_archived' => 0]);
        $type = $row(['name' => 'Заметка', 'sort_order' => 1, 'is_archived' => 0]);
        $parent = $row(['name' => 'Наблюдение', 'content' => "# Текст\n```php\necho 1;\n```\nПосле кода", 'format' => 0, 'status' => 1,
            'occurred_at' => '2025-01-15 08:30:00', 'section_id' => $section['id'], 'category_id' => $category['id'], 'type_id' => $type['id'],
            'is_pinned' => 1, 'is_locked' => 1, 'is_blurred' => 0, 'is_expert' => 1, 'sort_order' => 9, 'relation_type' => 0, 'access' => 3, 'comment_access' => 2]);
        $child = $row([...$parent, 'id' => Str::lower((string) Str::ulid()), 'name' => null, 'content' => 'Дочерняя', 'status' => 2, 'parent_id' => $parent['id']]);
        $tag = $row(['name' => 'Дом', 'slug' => 'home', 'color' => '#ff0000']);
        $content = $row(['source_module' => 'eventor', 'source_id' => $parent['id'], 'field' => 'content', 'kind' => 'markdown', 'is_primary' => 1, 'body_md' => $parent['content']]);
        $snapshot = ['schema' => 1, 'source' => 'home.zuratax.com', 'owner_id' => $sourceOwner, 'owner_hash' => hash('sha256', Str::lower($owner->email)),
            'sections' => [$section], 'categories' => [$category], 'types' => [$type], 'events' => [$child, $parent], 'contents' => [$content],
            'tags' => [$tag], 'event_tags' => [['event_id' => $parent['id'], 'tag_id' => $tag['id']]], 'contacts' => [], 'task_refs' => [], 'media' => [], 'embeds' => [], 'starred' => []];

        return [$scope, $owner, $snapshot];
    }

    public function test_dry_run_writes_nothing_and_commit_preserves_content_catalogs_hierarchy_and_dates(): void
    {
        [$scope, $owner, $snapshot] = $this->fixture();
        $typesBefore = EventType::count();
        $service = app(LegacyEventorImportService::class);

        $dry = $service->import($scope, $snapshot);

        $this->assertSame(5, $dry['planned']);
        $this->assertSame(0, $dry['created']);
        $this->assertDatabaseCount('events', 0);
        $this->assertDatabaseCount('event_types', $typesBefore);
        $this->assertDatabaseCount('legacy_import_records', 0);

        $result = $service->import($scope, $snapshot, true);

        $parentId = $result['maps']['events'][$snapshot['events'][1]['id']];
        $childId = $result['maps']['events'][$snapshot['events'][0]['id']];
        $this->assertSame(5, $result['created']);
        $this->assertSame($dry['maps'], $result['maps']);
        $this->assertDatabaseHas('events', ['id' => $parentId, 'scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'private', 'status' => 'published', 'content' => $snapshot['events'][1]['content'], 'occurred_at' => '2025-01-15 08:30:00', 'created_at' => '2025-01-01 10:00:00', 'is_locked' => true, 'root_id' => $parentId]);
        $this->assertDatabaseHas('events', ['id' => $childId, 'parent_id' => $parentId, 'root_id' => $parentId, 'status' => 'archived', 'title' => 'Без названия']);
        $this->assertDatabaseHas('event_sections', ['id' => $result['maps']['sections'][$snapshot['sections'][0]['id']], 'color' => '#aabbcc', 'background_color' => '#ddeeff', 'visibility' => 'private', 'sort_order' => 0]);
        $this->assertSame('Дом', Event::findOrFail($parentId)->meta['tags'][0]['name']);
        $this->assertDatabaseCount('legacy_import_records', 5);
        $raw = DB::table('legacy_import_records')->where('source_type', 'eventor_event')->value('snapshot');
        $this->assertStringNotContainsString('После кода', $raw);
        $this->assertDatabaseHas('activity_logs', ['action' => 'eventor.imported', 'actor_id' => $owner->id]);
    }

    public function test_repeated_import_is_empty_and_never_overwrites_native_edits_or_restores_deleted_events(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $service = app(LegacyEventorImportService::class);
        $first = $service->import($scope, $snapshot, true);
        $id = $first['maps']['events'][$snapshot['events'][1]['id']];
        Event::findOrFail($id)->update(['title' => 'Новый заголовок', 'content' => 'Новый контент', 'visibility' => 'scope']);
        Event::findOrFail($id)->delete();

        $repeat = $service->import($scope, $snapshot, true);

        $this->assertSame(0, $repeat['created']);
        $this->assertSame(5, array_sum($repeat['counts']['skip']));
        $this->assertSame('Новый контент', Event::withTrashed()->findOrFail($id)->content);
        $this->assertSoftDeleted('events', ['id' => $id]);
        $this->assertDatabaseCount('activity_logs', 1);
    }

    public function test_legacy_system_ids_short_colors_large_sort_orders_and_missing_type_creation_dates_are_normalized(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $snapshot['sections'][0]['id'] = '82QVM7M962PVZC2ASDV8DQRHK2';
        $snapshot['sections'][0]['color'] = 'abc';
        $snapshot['sections'][0]['sort_order'] = 6000000000000;
        $snapshot['types'][0] = [...$snapshot['types'][0], 'id' => 'SYS3THGLH8SI1XX5R3JV4FLUU2', 'user_id' => 'SYSTEM00000000000000000000', 'created_at' => null, 'color' => '#666'];
        $snapshot['tags'][0] = [...$snapshot['tags'][0], 'id' => 'SYSTAG000000000000BUY000', 'user_id' => null];
        $snapshot['event_tags'][0]['tag_id'] = $snapshot['tags'][0]['id'];
        foreach ($snapshot['events'] as &$row) {
            $row['section_id'] = $snapshot['sections'][0]['id'];
            $row['type_id'] = $snapshot['types'][0]['id'];
        }
        unset($row);

        $result = app(LegacyEventorImportService::class)->import($scope, $snapshot, true);

        $this->assertDatabaseHas('event_sections', ['id' => $result['maps']['sections'][strtolower($snapshot['sections'][0]['id'])], 'color' => '#aabbcc', 'sort_order' => 0]);
        $this->assertDatabaseHas('event_types', ['id' => $result['maps']['types'][strtolower($snapshot['types'][0]['id'])], 'color' => '#666666', 'created_at' => '2025-02-01 11:00:00']);
        $this->assertLessThanOrEqual(24, strlen(EventType::findOrFail($result['maps']['types'][strtolower($snapshot['types'][0]['id'])])->code));
        $this->assertSame(0, app(LegacyEventorImportService::class)->import($scope, $snapshot, true)['created']);
    }

    public function test_older_php_uniqid_event_identities_and_references_become_stable_native_ulids(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $old = 's_64db3a91865422.21382119';
        $snapshot['events'][1]['id'] = $old;
        $snapshot['events'][0]['parent_id'] = $old;
        $snapshot['contents'][0]['source_id'] = $old;
        $snapshot['event_tags'][0]['event_id'] = $old;

        $result = app(LegacyEventorImportService::class)->import($scope, $snapshot, true);

        $id = $result['maps']['events'][$old];
        $this->assertTrue(Str::isUlid($id));
        $this->assertDatabaseHas('events', ['parent_id' => $id]);
        $this->assertDatabaseHas('legacy_import_records', ['source_type' => 'eventor_event', 'source_id' => $old, 'target_id' => $id]);
        $this->assertSame(0, app(LegacyEventorImportService::class)->import($scope, $snapshot, true)['created']);
    }

    public function test_foreign_scope_external_mapping_is_rejected_without_creating_events(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $foreign = Project::factory()->create();
        $sourceId = Str::lower((string) Str::ulid());
        LegacyImportRecord::factory()->create(['scope_id' => $foreign->scope_id, 'created_by' => $foreign->created_by, 'source' => 'home.zuratax.com',
            'source_type' => 'tasker_project', 'source_id' => $sourceId, 'target_type' => 'project', 'target_id' => $foreign->id]);
        $snapshot['events'][0]['project_id'] = $sourceId;

        try {
            app(LegacyEventorImportService::class)->import($scope, $snapshot, true);
            $this->fail('Expected cross-scope rejection.');
        } catch (LogicException) {
            $this->assertDatabaseCount('events', 0);
            $this->assertDatabaseCount('legacy_import_records', 1);
        }
    }

    public function test_project_and_contact_links_use_verified_mapping_and_reuse_existing_links(): void
    {
        [$scope, $owner, $snapshot] = $this->fixture();
        $project = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $contact = Contact::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'private']);
        foreach (['tasker_project' => $project, 'contact' => $contact] as $kind => $record) {
            $oldId = Str::lower((string) Str::ulid());
            LegacyImportRecord::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'source' => 'home.zuratax.com', 'source_type' => $kind,
                'source_id' => $oldId, 'target_type' => $record->getMorphClass(), 'target_id' => $record->id]);
            if ($kind === 'tasker_project') {
                $snapshot['events'][1]['project_id'] = $oldId;
            } else {
                $snapshot['contacts'][] = ['id' => Str::lower((string) Str::ulid()), 'user_id' => $snapshot['owner_id'], 'event_id' => $snapshot['events'][1]['id'],
                    'contact_id' => $oldId, 'role' => 'Участник', 'note' => 'Пришёл', 'created_at' => '2025-01-01', 'updated_at' => '2025-01-01'];
            }
        }

        $result = app(LegacyEventorImportService::class)->import($scope, $snapshot, true);

        $eventId = $result['maps']['events'][$snapshot['events'][1]['id']];
        $this->assertDatabaseHas('events', ['id' => $eventId, 'project_id' => $project->id]);
        $this->assertDatabaseHas('entity_links', ['scope_id' => $scope->id, 'source_type' => 'event', 'source_id' => $eventId, 'target_type' => 'contact', 'target_id' => $contact->id, 'note' => 'Пришёл']);
        $this->assertSame(0, app(LegacyEventorImportService::class)->import($scope, $snapshot, true)['created']);
        $this->assertDatabaseCount('entity_links', 1);
    }

    public static function invalidSnapshots(): array
    {
        return array_map(fn ($case) => [$case], ['owner', 'duplicate', 'cycle', 'missing_section', 'missing_project', 'conflicting_md', 'unsupported_media', 'unsupported_status', 'foreign_tag']);
    }

    #[DataProvider('invalidSnapshots')]
    public function test_invalid_snapshots_fail_without_partial_writes(string $case): void
    {
        [$scope, , $snapshot] = $this->fixture();
        switch ($case) {
            case 'owner': $snapshot['events'][0]['user_id'] = Str::lower((string) Str::ulid());
                break;
            case 'duplicate': $snapshot['events'][] = $snapshot['events'][0];
                break;
            case 'cycle': $snapshot['events'][1]['parent_id'] = $snapshot['events'][0]['id'];
                break;
            case 'missing_section': $snapshot['events'][0]['section_id'] = Str::lower((string) Str::ulid());
                break;
            case 'missing_project': $snapshot['events'][0]['project_id'] = Str::lower((string) Str::ulid());
                break;
            case 'conflicting_md': $snapshot['contents'][0]['body_md'] = 'Другой контент';
                break;
            case 'unsupported_media': $snapshot['media'][] = ['id' => Str::lower((string) Str::ulid())];
                break;
            case 'unsupported_status': $snapshot['events'][0]['status'] = 99;
                break;
            case 'foreign_tag': $snapshot['tags'][0]['user_id'] = Str::lower((string) Str::ulid());
                break;
        }

        try {
            app(LegacyEventorImportService::class)->import($scope, $snapshot, true);
            $this->fail('Expected a safe import rejection.');
        } catch (LogicException) {
            $this->assertDatabaseCount('events', 0);
            $this->assertDatabaseCount('legacy_import_records', 0);
            $this->assertDatabaseCount('activity_logs', 0);
        }
    }

    public function test_changed_source_is_not_applied_over_an_existing_import(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $service = app(LegacyEventorImportService::class);
        $service->import($scope, $snapshot, true);
        $snapshot['events'][0]['content'] = 'Изменённый источник';

        try {
            $service->import($scope, $snapshot, true);
            $this->fail('Expected source drift rejection.');
        } catch (LogicException) {
            $this->assertDatabaseCount('events', 2);
            $this->assertDatabaseMissing('events', ['content' => 'Изменённый источник']);
        }
    }

    public function test_ambiguous_native_record_is_not_duplicated_or_overwritten(): void
    {
        [$scope, $owner, $snapshot] = $this->fixture();
        Event::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'title' => 'Наблюдение', 'occurred_at' => '2025-01-15 08:30:00', 'content' => 'Другое', 'visibility' => 'private']);

        try {
            app(LegacyEventorImportService::class)->import($scope, $snapshot, true);
            $this->fail('Expected provenance conflict.');
        } catch (LogicException) {
            $this->assertDatabaseCount('events', 1);
            $this->assertDatabaseCount('legacy_import_records', 0);
        }
    }

    public function test_failure_during_persistence_rolls_back_the_whole_batch(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $hook = 'eloquent.creating: '.LegacyImportRecord::class;
        Events::listen($hook, fn () => throw new RuntimeException('Simulated persistence failure'));

        try {
            app(LegacyEventorImportService::class)->import($scope, $snapshot, true);
            $this->fail('Expected persistence failure.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('events', 0);
            $this->assertDatabaseCount('event_sections', 0);
            $this->assertDatabaseCount('legacy_import_records', 0);
        } finally {
            Events::forget($hook);
        }
    }

    public function test_scope_and_owner_boundary_is_enforced(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $scope->update(['is_private' => false]);

        $this->expectException(LogicException::class);
        app(LegacyEventorImportService::class)->import($scope, $snapshot, true);
    }
}
