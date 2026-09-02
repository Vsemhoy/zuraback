<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ImportBookerRequest;
use App\Models\Book;
use App\Models\BookBlock;
use App\Models\BookBlockGroup;
use App\Models\BookPage;
use App\Models\Scope;
use App\Models\User;
use App\Services\ContractorAccessService;
use App\Services\ContractorContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class AgentBookerImportController extends Controller
{
    public function __construct(
        private readonly ContractorAccessService $access,
        private readonly ContractorContext $context,
    ) {}

    public function store(ImportBookerRequest $request, Scope $scope): JsonResponse
    {
        $data = $request->validated();
        $actor = $this->context->actor($request);
        abort_unless(
            $scope->owner_id === $actor->id || $this->access->membership($actor, $scope)?->book_access_mode === 'all',
            403,
            'Booker import requires full book access in the scope.',
        );
        $this->assertReferences($data);
        $plan = $this->plan($scope, $actor, $data);

        if ($request->boolean('dry_run')) {
            return response()->json(['data' => ['dry_run' => true, ...$plan]]);
        }

        $result = DB::transaction(fn (): array => $this->import($scope, $actor, $data));

        return response()->json(['data' => ['dry_run' => false, ...$result]], 201);
    }

    /** @param array<string, mixed> $data */
    private function plan(Scope $scope, User $actor, array $data): array
    {
        $bookId = $data['book']['external_id'];
        $book = Book::withTrashed()->find($bookId);
        if ($book !== null) {
            $this->assertImported($book->scope_id === $scope->id, $book->meta, $data['source'], $bookId);
            abort_unless($this->access->canAccessBook($actor, $scope, $book), 403, 'Imported book is outside the agent access boundary.');
        }

        $this->assertChildCollisions($data, $bookId);

        return [
            'source' => $data['source'],
            'book' => ['external_id' => $bookId, 'action' => $book === null ? 'create' : 'reuse'],
            'counts' => [
                'books' => $this->countPlan(Book::withTrashed()->whereKey($bookId)->count(), 1),
                'pages' => $this->countPlan(BookPage::withTrashed()->whereIn('id', collect($data['pages'])->pluck('external_id'))->count(), count($data['pages'])),
                'groups' => $this->countPlan(BookBlockGroup::withTrashed()->whereIn('id', collect($data['groups'])->pluck('external_id'))->count(), count($data['groups'])),
                'blocks' => $this->countPlan(BookBlock::withTrashed()->whereIn('id', collect($data['blocks'])->pluck('external_id'))->count(), count($data['blocks'])),
            ],
        ];
    }

    /** @param array<string, mixed> $data */
    private function import(Scope $scope, User $actor, array $data): array
    {
        $counts = collect(['books', 'pages', 'groups', 'blocks'])
            ->mapWithKeys(fn (string $type): array => [$type => ['created' => 0, 'reused' => 0]])
            ->all();
        $bookPayload = $data['book'];
        $book = Book::withTrashed()->find($bookPayload['external_id']);
        if ($book === null) {
            $book = Book::query()->forceCreate([
                ...Arr::except($bookPayload, ['external_id', 'meta']),
                'id' => strtolower($bookPayload['external_id']),
                'scope_id' => $scope->id,
                'space_id' => null,
                'project_id' => null,
                'created_by' => $actor->id,
                'meta' => $this->importMeta($bookPayload['meta'] ?? null, $data['source'], $bookPayload['external_id']),
            ]);
            $counts['books']['created']++;
        } else {
            $counts['books']['reused']++;
        }

        $pages = [];
        foreach ($data['pages'] as $payload) {
            $page = BookPage::withTrashed()->find($payload['external_id']);
            if ($page === null) {
                $page = BookPage::query()->forceCreate([
                    ...Arr::except($payload, ['external_id', 'parent_external_id', 'meta']),
                    'id' => strtolower($payload['external_id']),
                    'book_id' => $book->id,
                    'parent_id' => null,
                    'created_by' => $actor->id,
                    'meta' => $this->importMeta($payload['meta'] ?? null, $data['source'], $payload['external_id']),
                ]);
                $counts['pages']['created']++;
            } else {
                $counts['pages']['reused']++;
            }
            $pages[$payload['external_id']] = $page;
        }
        foreach ($data['pages'] as $payload) {
            $pages[$payload['external_id']]->updateQuietly([
                'parent_id' => empty($payload['parent_external_id']) ? null : $pages[$payload['parent_external_id']]->id,
            ]);
        }

        $groups = [];
        foreach ($data['groups'] as $payload) {
            $group = BookBlockGroup::withTrashed()->find($payload['external_id']);
            if ($group === null) {
                $group = BookBlockGroup::query()->forceCreate([
                    ...Arr::except($payload, ['external_id', 'page_external_id', 'master_block_external_id', 'meta']),
                    'id' => strtolower($payload['external_id']),
                    'page_id' => $pages[$payload['page_external_id']]->id,
                    'master_block_id' => null,
                    'created_by' => $actor->id,
                    'meta' => $this->importMeta($payload['meta'] ?? null, $data['source'], $payload['external_id']),
                ]);
                $counts['groups']['created']++;
            } else {
                $counts['groups']['reused']++;
            }
            $groups[$payload['external_id']] = $group;
        }

        $blocks = [];
        foreach ($data['blocks'] as $payload) {
            $block = BookBlock::withTrashed()->find($payload['external_id']);
            if ($block === null) {
                $block = BookBlock::query()->forceCreate([
                    ...Arr::except($payload, ['external_id', 'group_external_id']),
                    'id' => strtolower($payload['external_id']),
                    'group_id' => $groups[$payload['group_external_id']]->id,
                    'created_by' => $actor->id,
                    'search_text' => $this->searchText($payload),
                ]);
                $counts['blocks']['created']++;
            } else {
                $counts['blocks']['reused']++;
            }
            $blocks[$payload['external_id']] = $block;
        }
        foreach ($data['groups'] as $payload) {
            $groups[$payload['external_id']]->updateQuietly([
                'master_block_id' => $blocks[$payload['master_block_external_id']]->id,
            ]);
        }

        return [
            'source' => $data['source'],
            'book_id' => $book->id,
            'counts' => $counts,
        ];
    }

    /** @param array<string, mixed> $data */
    private function assertReferences(array $data): void
    {
        $pageIds = collect($data['pages'])->pluck('external_id')->all();
        $groupIds = collect($data['groups'])->pluck('external_id')->all();
        $blockIds = collect($data['blocks'])->pluck('external_id')->all();
        foreach ($data['pages'] as $page) {
            abort_if(! empty($page['parent_external_id']) && ! in_array($page['parent_external_id'], $pageIds, true), 422, "Unknown parent page {$page['parent_external_id']}.");
        }
        foreach ($data['groups'] as $group) {
            abort_unless(in_array($group['page_external_id'], $pageIds, true), 422, "Unknown page {$group['page_external_id']}.");
            abort_unless(in_array($group['master_block_external_id'], $blockIds, true), 422, "Unknown master block {$group['master_block_external_id']}.");
        }
        foreach ($data['blocks'] as $block) {
            abort_unless(in_array($block['group_external_id'], $groupIds, true), 422, "Unknown block group {$block['group_external_id']}.");
        }
    }

    /** @param array<string, mixed> $data */
    private function assertChildCollisions(array $data, string $bookId): void
    {
        $source = $data['source'];
        $pageBookIds = collect($data['pages'])->mapWithKeys(fn (array $page): array => [$page['external_id'] => $bookId]);
        foreach (BookPage::withTrashed()->whereIn('id', $pageBookIds->keys())->get() as $page) {
            $this->assertImported($page->book_id === $pageBookIds[$page->id], $page->meta, $source, $page->id);
        }
        $groupPageIds = collect($data['groups'])->mapWithKeys(fn (array $group): array => [$group['external_id'] => $group['page_external_id']]);
        foreach (BookBlockGroup::withTrashed()->whereIn('id', $groupPageIds->keys())->get() as $group) {
            $this->assertImported($group->page_id === $groupPageIds[$group->id], $group->meta, $source, $group->id);
        }
        $blockGroupIds = collect($data['blocks'])->mapWithKeys(fn (array $block): array => [$block['external_id'] => $block['group_external_id']]);
        foreach (BookBlock::withTrashed()->whereIn('id', $blockGroupIds->keys())->get() as $block) {
            abort_unless($block->group_id === $blockGroupIds[$block->id], 409, "Block ID {$block->id} is already in use.");
            $group = BookBlockGroup::withTrashed()->findOrFail($block->group_id);
            $this->assertImported(true, $group->meta, $source, $group->id);
        }
    }

    /** @param array<string, mixed>|null $meta */
    private function assertImported(bool $belongs, ?array $meta, string $source, string $externalId): void
    {
        abort_unless(
            $belongs
                && ($meta['import']['source'] ?? null) === $source
                && strcasecmp((string) ($meta['import']['external_id'] ?? ''), $externalId) === 0,
            409,
            "External ID {$externalId} is already in use by a non-imported record.",
        );
    }

    /** @param array<string, mixed>|null $meta @return array<string, mixed> */
    private function importMeta(?array $meta, string $source, string $externalId): array
    {
        return [...($meta ?? []), 'import' => ['source' => $source, 'external_id' => $externalId]];
    }

    /** @param array<string, mixed> $payload */
    private function searchText(array $payload): string
    {
        return trim(implode("\n", array_filter([
            $payload['title'] ?? null,
            $payload['content'] ?? null,
            empty($payload['payload']) ? null : json_encode($payload['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ])));
    }

    /** @return array{create: int, reuse: int} */
    private function countPlan(int $reused, int $total): array
    {
        return ['create' => $total - $reused, 'reuse' => $reused];
    }
}
