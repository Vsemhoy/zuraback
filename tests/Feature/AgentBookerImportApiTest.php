<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookBlock;
use App\Models\Scope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentBookerImportApiTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-App-Request' => 'Zuratax'];

    public function test_agent_can_dry_run_and_idempotently_import_a_complete_private_book(): void
    {
        [$agent, $scope, $token] = $this->agentWorkspace();
        $bookId = strtolower((string) Str::ulid());
        $pageId = strtolower((string) Str::ulid());
        $groupId = strtolower((string) Str::ulid());
        $firstBlockId = strtolower((string) Str::ulid());
        $masterBlockId = strtolower((string) Str::ulid());
        $payload = [
            'source' => 'teftele-2026-booker',
            'book' => [
                'external_id' => $bookId,
                'title' => 'Legacy handbook',
                'slug' => null,
                'description' => 'Imported with full structure.',
                'structure_mode' => 'tree',
                'visibility' => 'private',
                'cover_color' => '#123456',
                'cover_svg_url' => null,
                'cover_svg_text' => '<svg></svg>',
                'export_settings' => null,
                'sort_order' => 10,
                'is_archived' => false,
                'meta' => ['legacy' => true],
                'created_at' => '2026-08-01 10:00:00',
                'updated_at' => '2026-08-02 10:00:00',
            ],
            'pages' => [[
                'external_id' => $pageId,
                'parent_external_id' => null,
                'title' => 'First page',
                'slug' => 'first-page',
                'visibility' => 'private',
                'sort_order' => 1,
                'is_archived' => false,
                'meta' => null,
                'created_at' => '2026-08-01 10:00:00',
                'updated_at' => '2026-08-02 10:00:00',
            ]],
            'groups' => [[
                'external_id' => $groupId,
                'page_external_id' => $pageId,
                'master_block_external_id' => $masterBlockId,
                'type' => 'markdown',
                'role' => 'content',
                'visibility' => 'private',
                'is_hidden_by_default' => false,
                'sort_order' => 1,
                'meta' => null,
                'created_at' => '2026-08-01 10:00:00',
                'updated_at' => '2026-08-02 10:00:00',
            ]],
            'blocks' => [
                [
                    'external_id' => $firstBlockId,
                    'group_external_id' => $groupId,
                    'version_number' => 1,
                    'title' => 'Old version',
                    'content' => 'Before',
                    'payload' => null,
                    'status' => 'draft',
                    'published_at' => null,
                    'created_at' => '2026-08-01 10:00:00',
                    'updated_at' => '2026-08-01 10:00:00',
                ],
                [
                    'external_id' => $masterBlockId,
                    'group_external_id' => $groupId,
                    'version_number' => 2,
                    'title' => 'Current version',
                    'content' => 'After',
                    'payload' => ['tone' => 'note'],
                    'status' => 'draft',
                    'published_at' => null,
                    'created_at' => '2026-08-02 10:00:00',
                    'updated_at' => '2026-08-02 10:00:00',
                ],
            ],
        ];

        $this->withToken($token)->postJson("/api/agent/scopes/{$scope->id}/imports/booker", [...$payload, 'dry_run' => true])
            ->assertOk()
            ->assertJsonPath('data.counts.books.create', 1)
            ->assertJsonPath('data.counts.blocks.create', 2);
        $this->assertDatabaseCount('books', 0);

        $this->withToken($token)->postJson("/api/agent/scopes/{$scope->id}/imports/booker", $payload)
            ->assertCreated()
            ->assertJsonPath('data.counts.books.created', 1)
            ->assertJsonPath('data.counts.pages.created', 1)
            ->assertJsonPath('data.counts.groups.created', 1)
            ->assertJsonPath('data.counts.blocks.created', 2);

        $this->assertDatabaseHas('books', ['id' => $bookId, 'scope_id' => $scope->id, 'created_by' => $agent->id, 'visibility' => 'private']);
        $this->assertDatabaseHas('book_pages', ['id' => $pageId, 'book_id' => $bookId]);
        $this->assertDatabaseHas('book_block_groups', ['id' => $groupId, 'master_block_id' => $masterBlockId]);
        $this->assertDatabaseHas('book_blocks', ['id' => $masterBlockId, 'version_number' => 2, 'content' => 'After']);
        $this->assertSame('teftele-2026-booker', Book::query()->findOrFail($bookId)->meta['import']['source']);
        $this->assertStringContainsString('After', BookBlock::query()->findOrFail($masterBlockId)->search_text);

        $this->withToken($token)->postJson("/api/agent/scopes/{$scope->id}/imports/booker", $payload)
            ->assertCreated()
            ->assertJsonPath('data.counts.books.reused', 1)
            ->assertJsonPath('data.counts.blocks.reused', 2);
        $this->assertDatabaseCount('books', 1);
        $this->assertDatabaseCount('book_blocks', 2);

        $colleague = User::factory()->create();
        $scope->members()->create([
            'user_id' => $colleague->id,
            'role' => 'member',
            'project_access_mode' => 'all',
            'book_access_mode' => 'all',
            'permissions' => ['allow' => ['book.view'], 'deny' => []],
            'joined_at' => now(),
        ]);
        $this->actingAs($colleague)->withHeaders(self::HEADERS)
            ->getJson("/api/scopes/{$scope->id}/books")
            ->assertOk()->assertJsonCount(0, 'data');
    }

    /** @return array{User, Scope, string} */
    private function agentWorkspace(): array
    {
        $owner = User::factory()->create();
        $agent = User::factory()->agent()->create(['status' => 'active', 'is_active' => true]);
        $scope = Scope::factory()->create(['owner_id' => $owner->id]);
        $scope->members()->create([
            'user_id' => $agent->id,
            'role' => 'member',
            'permissions' => ['allow' => ['book.view', 'book.create', 'book.update'], 'deny' => []],
            'project_access_mode' => 'all',
            'book_access_mode' => 'all',
            'joined_at' => now(),
        ]);
        $token = $agent->createToken('Book importer', ['book.view', 'book.create', 'book.update'])->plainTextToken;

        return [$agent, $scope, $token];
    }
}
