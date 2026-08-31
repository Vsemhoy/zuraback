<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookPageResource;
use App\Models\Book;
use App\Models\BookPage;
use App\Models\BookBlockGroup;
use App\Models\BookPageVersion;
use App\Models\Scope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookPageEditingController extends Controller
{
    private const LOCK_MINUTES = 30;

    public function acquire(Request $request, Scope $scope, Book $book, BookPage $bookPage): BookPageResource|JsonResponse
    {
        $this->assertPageInScope($scope, $book, $bookPage);

        $result = DB::transaction(function () use ($request, $book, $bookPage): BookPage|JsonResponse {
            $page = BookPage::query()->lockForUpdate()->findOrFail($bookPage->id);
            $lockIsFresh = $page->editing_started_at?->isAfter(now()->subMinutes(self::LOCK_MINUTES));

            if ($page->editing_by && $page->editing_by !== $request->user()->id && $lockIsFresh) {
                $page->load('editor:id,name,username');
                return response()->json([
                    'message' => 'Страница уже редактируется.',
                    'editor' => $page->editor,
                    'editing_started_at' => $page->editing_started_at,
                ], 423);
            }

            if ($page->editing_by !== $request->user()->id || !$lockIsFresh) {
                $page->load(['groups' => fn ($query) => $query->orderBy('sort_order'), 'groups.masterBlock']);
                $nextVersion = ((int) $page->versions()->max('version_number')) + 1;
                $page->versions()->create([
                    'created_by' => $request->user()->id,
                    'version_number' => $nextVersion,
                    'snapshot' => $this->snapshot($page),
                ]);
                $page->update(['editing_by' => $request->user()->id, 'editing_started_at' => now()]);

                $keep = max(1, min(100, (int) $book->version_depth));
                $obsolete = $page->versions()->latest('version_number')->skip($keep)->take(1000)->pluck('id');
                if ($obsolete->isNotEmpty()) {
                    $page->versions()->whereIn('id', $obsolete)->delete();
                }
            }

            return $page;
        });

        return $result instanceof JsonResponse
            ? $result
            : new BookPageResource($result->fresh()->load(['editor:id,name,username', 'groups.masterBlock'])->loadCount('versions'));
    }

    public function release(Request $request, Scope $scope, Book $book, BookPage $bookPage): BookPageResource
    {
        $this->assertPageInScope($scope, $book, $bookPage);
        abort_unless($bookPage->editing_by === $request->user()->id, 409, 'Эта страница редактируется другим пользователем.');
        $bookPage->update(['editing_by' => null, 'editing_started_at' => null]);

        return new BookPageResource($bookPage->fresh()->load(['editor:id,name,username', 'groups.masterBlock'])->loadCount('versions'));
    }

    public function cancel(Request $request, Scope $scope, Book $book, BookPage $bookPage): BookPageResource
    {
        $this->assertPageInScope($scope, $book, $bookPage);
        abort_unless($bookPage->editing_by === $request->user()->id, 409, 'Эта страница редактируется другим пользователем.');

        DB::transaction(function () use ($bookPage): void {
            $page = BookPage::query()->lockForUpdate()->findOrFail($bookPage->id);
            $version = $page->versions()->latest('version_number')->firstOrFail();
            $snapshot = $version->snapshot;
            $snapshotGroups = collect($snapshot['blocks'] ?? []);
            $snapshotIds = $snapshotGroups->pluck('group.id')->filter()->all();

            $page->groups()->whereNotIn('id', $snapshotIds)->delete();
            foreach ($snapshotGroups as $entry) {
                $groupData = $entry['group'] ?? [];
                $blockData = $entry['block'] ?? null;
                $group = BookBlockGroup::withTrashed()->find($groupData['id'] ?? null);
                if (!$group) continue;
                if ($group->trashed()) $group->restore();
                $group->update([
                    'type' => $groupData['type'] ?? 'markdown',
                    'role' => $groupData['role'] ?? 'content',
                    'visibility' => $groupData['visibility'] ?? 'private',
                    'is_hidden_by_default' => $groupData['is_hidden_by_default'] ?? false,
                    'sort_order' => $groupData['sort_order'] ?? 0,
                    'meta' => $groupData['meta'] ?? null,
                    'master_block_id' => $blockData['id'] ?? null,
                ]);
            }

            $pageData = $snapshot['page'] ?? [];
            $page->update([
                'parent_id' => $pageData['parent_id'] ?? null,
                'title' => $pageData['title'] ?? $page->title,
                'slug' => $pageData['slug'] ?? null,
                'visibility' => $pageData['visibility'] ?? 'private',
                'sort_order' => $pageData['sort_order'] ?? 0,
                'meta' => $pageData['meta'] ?? null,
                'editing_by' => null,
                'editing_started_at' => null,
            ]);
            $version->delete();
        });

        return new BookPageResource($bookPage->fresh()->load(['editor:id,name,username', 'groups.masterBlock'])->loadCount('versions'));
    }

    public function versions(Scope $scope, Book $book, BookPage $bookPage): JsonResponse
    {
        $this->assertPageInScope($scope, $book, $bookPage);
        $versions = $bookPage->versions()->with('creator:id,name,username')->latest('version_number')->get()
            ->map(fn (BookPageVersion $version) => [
                'id' => $version->id,
                'version_number' => $version->version_number,
                'created_at' => $version->created_at,
                'creator' => $version->creator,
            ]);

        return response()->json(['data' => $versions]);
    }

    public function version(Scope $scope, Book $book, BookPage $bookPage, BookPageVersion $version): JsonResponse
    {
        $this->assertPageInScope($scope, $book, $bookPage);
        abort_unless($version->page_id === $bookPage->id, 404);

        return response()->json(['data' => [
            'id' => $version->id,
            'version_number' => $version->version_number,
            'created_at' => $version->created_at,
            'snapshot' => $version->snapshot,
        ]]);
    }

    private function snapshot(BookPage $page): array
    {
        return [
            'format' => 'booker-page',
            'format_version' => 1,
            'page' => $page->only(['id', 'book_id', 'parent_id', 'title', 'slug', 'visibility', 'sort_order', 'meta']),
            'blocks' => $page->groups->map(fn ($group) => [
                'group' => $group->only(['id', 'type', 'role', 'visibility', 'is_hidden_by_default', 'sort_order', 'meta']),
                'block' => $group->masterBlock?->only(['id', 'version_number', 'title', 'content', 'payload', 'search_text', 'status', 'published_at']),
            ])->values()->all(),
        ];
    }

    private function assertPageInScope(Scope $scope, Book $book, BookPage $bookPage): void
    {
        abort_unless($book->scope_id === $scope->id && $bookPage->book_id === $book->id, 404);
    }
}
