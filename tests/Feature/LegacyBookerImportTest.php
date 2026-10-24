<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookBlock;
use App\Models\BookPage;
use App\Models\LegacyImportRecord;
use App\Models\Scope;
use App\Models\User;
use App\Services\ContractorAccessService;
use App\Services\LegacyBookerImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class LegacyBookerImportTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $owner = User::factory()->create();
        $scope = Scope::factory()->create(['owner_id' => $owner->id, 'is_private' => true]);
        $legacyOwner = (string) Str::ulid();
        $row = fn (array $extra): array => ['id' => Str::lower((string) Str::ulid()), 'user_id' => $legacyOwner,
            'created_at' => '2025-04-01 10:00:00', 'updated_at' => '2025-04-02 11:00:00', 'deleted_at' => null, 'sort_order' => 0, ...$extra];
        $space = $row(['title' => 'Полка', 'slug' => 'shelf']);
        $book = $row(['space_id' => $space['id'], 'title' => 'Старая книга', 'slug' => 'old-book', 'structure_mode' => 'tree', 'description' => 'Описание', 'visibility' => 'public', 'cover_svg_text' => '<svg><rect width="3"/></svg>', 'export_settings' => '{"paper":"A4"}']);
        $page = $row(['book_id' => $book['id'], 'title' => 'Первая', 'slug' => 'first']);
        $child = $row(['book_id' => $book['id'], 'parent_id' => $page['id'], 'title' => 'Дочерняя', 'slug' => 'child']);
        $group = $row(['page_id' => $page['id'], 'type' => 'markdown', 'role' => 'content']);
        $first = $row(['group_id' => $group['id'], 'version_number' => 1, 'content' => "# Заголовок\n```js\nx()\n```\nПосле кода", 'status' => 'draft']);
        $second = $row(['group_id' => $group['id'], 'version_number' => 2, 'content' => 'Другая версия', 'status' => 'draft']);
        $group['master_block_id'] = $first['id'];
        $drawing = $row(['page_id' => $child['id'], 'type' => 'excalidraw', 'role' => 'content']);
        $scene = $row(['group_id' => $drawing['id'], 'version_number' => 1, 'status' => 'draft', 'payload' => '{"svg":"<svg></svg>","scene":{"elements":[{"id":"keep-scene-id","type":"rectangle"}]}}']);
        $drawing['master_block_id'] = $scene['id'];
        $svg = $row(['page_id' => $child['id'], 'type' => 'svg', 'role' => 'content', 'sort_order' => 3]);
        $svgBlock = $row(['group_id' => $svg['id'], 'version_number' => 1, 'status' => 'draft', 'payload' => '{"source":"inline","svg_text":"<svg><circle r=\"4\"/></svg>","caption":"Подпись","max_width":600}']);
        $svg['master_block_id'] = $svgBlock['id'];
        $snapshot = ['schema' => 1, 'source' => 'home.zuratax.com', 'owner_id' => $legacyOwner, 'owner_hash' => hash('sha256', Str::lower($owner->email)),
            'spaces' => [$space], 'books' => [$book], 'pages' => [$page, $child], 'groups' => [$group, $drawing, $svg], 'blocks' => [$first, $second, $scene, $svgBlock]];

        return [$scope, $owner, $snapshot];
    }

    public function test_dry_run_checks_all_nodes_without_writing(): void
    {
        [$scope, , $snapshot] = $this->fixture();

        $plan = app(LegacyBookerImportService::class)->import($scope, $snapshot);

        $this->assertSame(11, $plan['planned']);
        $this->assertSame(0, $plan['created']);
        foreach (['books', 'book_spaces', 'book_pages', 'book_block_groups', 'book_blocks', 'legacy_import_records'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_import_preserves_full_tree_all_versions_exact_master_and_embedded_payloads(): void
    {
        [$scope, $owner, $snapshot] = $this->fixture();

        $result = app(LegacyBookerImportService::class)->import($scope, $snapshot, true);

        $maps = $result['maps'];
        $bookId = $maps['books'][$snapshot['books'][0]['id']];
        $pageId = $maps['pages'][$snapshot['pages'][0]['id']];
        $childId = $maps['pages'][$snapshot['pages'][1]['id']];
        $groupId = $maps['groups'][$snapshot['groups'][0]['id']];
        $master = $maps['blocks'][$snapshot['blocks'][0]['id']];
        $this->assertDatabaseHas('books', ['id' => $bookId, 'space_id' => $maps['spaces'][$snapshot['spaces'][0]['id']], 'created_by' => $owner->id, 'visibility' => 'private', 'cover_svg_text' => $snapshot['books'][0]['cover_svg_text']]);
        $this->assertDatabaseHas('book_pages', ['id' => $childId, 'book_id' => $bookId, 'parent_id' => $pageId, 'editing_by' => null]);
        $this->assertDatabaseHas('book_block_groups', ['id' => $groupId, 'page_id' => $pageId, 'master_block_id' => $master]);
        $this->assertDatabaseHas('book_blocks', ['id' => $master, 'group_id' => $groupId, 'content' => $snapshot['blocks'][0]['content'], 'version_number' => 1, 'status' => 'draft']);
        $this->assertDatabaseCount('book_blocks', 4);
        $this->assertSame(json_decode($snapshot['blocks'][2]['payload'], true), BookBlock::findOrFail($maps['blocks'][$snapshot['blocks'][2]['id']])->payload);
        $this->assertSame(json_decode($snapshot['blocks'][3]['payload'], true), BookBlock::findOrFail($maps['blocks'][$snapshot['blocks'][3]['id']])->payload);
        $this->assertStringContainsString('После кода', BookBlock::findOrFail($master)->search_text);
        $this->assertNotSame($snapshot['books'][0]['id'], $bookId);
        $this->assertDatabaseCount('legacy_import_records', 11);
    }

    public function test_rerun_preserves_newer_user_content_and_does_not_duplicate_any_nodes(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $service = app(LegacyBookerImportService::class);
        $result = $service->import($scope, $snapshot, true);
        $blockId = $result['maps']['blocks'][$snapshot['blocks'][0]['id']];
        BookBlock::findOrFail($blockId)->update(['content' => 'Edited in Zuratax']);
        Book::sole()->update(['title' => 'Renamed book']);

        $repeat = $service->import($scope, $snapshot, true);

        $this->assertSame(0, $repeat['created']);
        $this->assertSame(11, array_sum($repeat['counts']['skip']));
        $this->assertDatabaseCount('books', 1);
        $this->assertDatabaseCount('book_blocks', 4);
        $this->assertDatabaseHas('book_blocks', ['id' => $blockId, 'content' => 'Edited in Zuratax']);
        $this->assertDatabaseHas('books', ['title' => 'Renamed book']);
    }

    public function test_original_id_collisions_in_other_scopes_do_not_modify_those_records(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $other = Book::factory()->create(['id' => $snapshot['books'][0]['id'], 'title' => 'Other scope book']);
        $otherPage = BookPage::factory()->create(['id' => $snapshot['pages'][0]['id'], 'book_id' => $other->id, 'title' => 'Other scope page']);

        $result = app(LegacyBookerImportService::class)->import($scope, $snapshot, true);

        $this->assertNotSame($other->id, $result['maps']['books'][$snapshot['books'][0]['id']]);
        $this->assertDatabaseHas('books', ['id' => $other->id, 'title' => 'Other scope book']);
        $this->assertDatabaseHas('book_pages', ['id' => $otherPage->id, 'title' => 'Other scope page']);
        $this->assertSame(1, $scope->books()->count());
    }

    public function test_proven_existing_book_is_reused_without_overwriting_its_properties(): void
    {
        [$scope, $owner, $snapshot] = $this->fixture();
        $book = Book::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'title' => 'Старая книга', 'slug' => 'old-book', 'description' => 'Keep current description', 'created_at' => '2025-04-01 10:00:00']);

        $result = app(LegacyBookerImportService::class)->import($scope, $snapshot, true);

        $this->assertSame($book->id, $result['maps']['books'][$snapshot['books'][0]['id']]);
        $this->assertDatabaseCount('books', 1);
        $this->assertDatabaseHas('books', ['id' => $book->id, 'description' => 'Keep current description']);
    }

    public function test_ambiguous_book_title_or_slug_is_not_silently_duplicated(): void
    {
        [$scope, $owner, $snapshot] = $this->fixture();
        Book::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'title' => 'Старая книга', 'slug' => 'old-book', 'created_at' => '2026-01-01 00:00:00']);

        try {
            app(LegacyBookerImportService::class)->import($scope, $snapshot, true);
            $this->fail('An uncertain duplicate requires review.');
        } catch (LogicException) {
            $this->assertDatabaseCount('books', 1);
            $this->assertDatabaseCount('legacy_import_records', 0);
        }
    }

    public function test_wrong_group_master_or_missing_version_rejects_entire_tree(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $snapshot['groups'][0]['master_block_id'] = $snapshot['blocks'][2]['id'];

        try {
            app(LegacyBookerImportService::class)->import($scope, $snapshot, true);
            $this->fail('A master cannot belong to another group.');
        } catch (LogicException) {
            $this->assertDatabaseCount('books', 0);
            $this->assertDatabaseCount('legacy_import_records', 0);
        }
    }

    public function test_cyclic_page_hierarchy_rejects_entire_tree(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $snapshot['pages'][0]['parent_id'] = $snapshot['pages'][1]['id'];

        $this->expectException(LogicException::class);
        app(LegacyBookerImportService::class)->import($scope, $snapshot, true);
    }

    public function test_repeated_version_number_rejects_entire_tree(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $snapshot['blocks'][1]['version_number'] = 1;

        $this->expectException(LogicException::class);
        app(LegacyBookerImportService::class)->import($scope, $snapshot, true);
    }

    public function test_archive_failure_rolls_back_book_and_every_child(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $event = 'eloquent.creating: '.LegacyImportRecord::class;
        Event::listen($event, function (): void {
            throw new RuntimeException('Synthetic archive failure');
        });

        try {
            app(LegacyBookerImportService::class)->import($scope, $snapshot, true);
            $this->fail('The whole tree must roll back.');
        } catch (RuntimeException) {
            foreach (['book_spaces', 'books', 'book_pages', 'book_block_groups', 'book_blocks', 'legacy_import_records'] as $table) {
                $this->assertDatabaseCount($table, 0);
            }
        } finally {
            Event::forget($event);
        }
    }

    public function test_scope_administrator_cannot_read_someone_elses_imported_private_book(): void
    {
        [$scope, $owner, $snapshot] = $this->fixture();
        app(LegacyBookerImportService::class)->import($scope, $snapshot, true);
        $member = User::factory()->create();
        $scope->members()->create(['user_id' => $member->id, 'role' => 'admin', 'project_access_mode' => 'all', 'book_access_mode' => 'all', 'is_active' => true]);
        $access = app(ContractorAccessService::class);

        $this->assertTrue($access->canAccessBook($owner, $scope, Book::sole()));
        $this->assertFalse($access->canAccessBook($member, $scope, Book::sole()));
    }

    public function test_cli_dry_run_requires_checksum_before_commit(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $file = tempnam(sys_get_temp_dir(), 'legacy-book-test-');
        $bytes = json_encode($snapshot, JSON_THROW_ON_ERROR);
        file_put_contents($file, $bytes);
        chmod($file, 0600);

        try {
            $this->artisan('legacy:import-booker', ['snapshot' => $file, '--scope' => $scope->id])->assertSuccessful();
            $this->assertDatabaseCount('books', 0);
            $this->artisan('legacy:import-booker', ['snapshot' => $file, '--scope' => $scope->id, '--commit' => true])->assertFailed();
            $this->assertDatabaseCount('books', 0);
            $this->artisan('legacy:import-booker', ['snapshot' => $file, '--scope' => $scope->id, '--commit' => true, '--expect-sha256' => hash('sha256', $bytes)])->assertSuccessful();
            $this->assertDatabaseCount('books', 1);
        } finally {
            unlink($file);
        }
    }

    public function test_modified_source_is_rejected_without_overwriting_user_data(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $service = app(LegacyBookerImportService::class);
        $result = $service->import($scope, $snapshot, true);
        $snapshot['blocks'][0]['content'] = 'Changed on the source';

        try {
            $service->import($scope, $snapshot, true);
            $this->fail('Changed source requires review.');
        } catch (LogicException) {
            $this->assertDatabaseHas('book_blocks', ['id' => $result['maps']['blocks'][$snapshot['blocks'][0]['id']], 'content' => "# Заголовок\n```js\nx()\n```\nПосле кода"]);
            $this->assertDatabaseCount('legacy_import_records', 11);
        }
    }

    public function test_same_looking_blocks_are_kept_distinct_and_deleted_pages_are_not_restored(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $group = [...$snapshot['groups'][0], 'id' => Str::lower((string) Str::ulid())];
        $block = [...$snapshot['blocks'][0], 'id' => Str::lower((string) Str::ulid()), 'group_id' => $group['id']];
        $group['master_block_id'] = $block['id'];
        $snapshot['groups'][] = $group;
        $snapshot['blocks'][] = $block;
        $service = app(LegacyBookerImportService::class);
        $result = $service->import($scope, $snapshot, true);
        $pageId = $result['maps']['pages'][$snapshot['pages'][0]['id']];
        BookPage::findOrFail($pageId)->delete();

        $repeat = $service->import($scope, $snapshot);

        $this->assertSame(0, $repeat['planned']);
        $this->assertSame(13, array_sum($repeat['counts']['skip']));
        $this->assertSoftDeleted('book_pages', ['id' => $pageId]);
        $this->assertDatabaseCount('book_block_groups', 4);
        $this->assertDatabaseCount('book_blocks', 5);
    }

    public function test_personal_import_refuses_public_scope_and_another_owner_identity(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $scope->update(['is_private' => false]);

        try {
            app(LegacyBookerImportService::class)->import($scope, $snapshot, true);
            $this->fail('Public destination must be refused.');
        } catch (LogicException) {
            $this->assertDatabaseCount('books', 0);
            $this->assertDatabaseCount('legacy_import_records', 0);
        }
        $scope->update(['is_private' => true]);
        $snapshot['owner_hash'] = str_repeat('0', 64);
        $this->expectException(LogicException::class);
        app(LegacyBookerImportService::class)->import($scope, $snapshot, true);
    }

    public function test_existing_page_edit_lock_prevents_merging_into_it(): void
    {
        [$scope, $owner, $snapshot] = $this->fixture();
        $book = Book::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'title' => 'Старая книга', 'slug' => 'old-book', 'created_at' => '2025-04-01 10:00:00']);
        $page = BookPage::factory()->create(['book_id' => $book->id, 'created_by' => $owner->id, 'title' => 'Первая', 'slug' => 'first', 'editing_by' => $owner->id, 'created_at' => '2025-04-01 10:00:00']);

        try {
            app(LegacyBookerImportService::class)->import($scope, $snapshot, true);
            $this->fail('Active editor must be respected.');
        } catch (LogicException) {
            $this->assertDatabaseHas('book_pages', ['id' => $page->id, 'editing_by' => $owner->id]);
            $this->assertDatabaseCount('book_block_groups', 0);
            $this->assertDatabaseCount('legacy_import_records', 0);
        }
    }
}
