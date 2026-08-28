<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreBookBlockGroupRequest;
use App\Http\Requests\Api\StoreBookBlockVersionRequest;
use App\Http\Requests\Api\ReorderBookBlocksRequest;
use App\Http\Resources\BookBlockGroupResource;
use App\Models\Book;
use App\Models\BookBlockGroup;
use App\Models\BookPage;
use App\Models\Scope;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;

class BookBlockGroupController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Scope $scope, Book $book, BookPage $bookPage): AnonymousResourceCollection
    {
        $this->assertPageInScope($scope, $book, $bookPage);

        return BookBlockGroupResource::collection($bookPage->groups()->with('masterBlock')->orderBy('sort_order')->get());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreBookBlockGroupRequest $request, Scope $scope, Book $book, BookPage $bookPage): BookBlockGroupResource
    {
        $this->assertPageInScope($scope, $book, $bookPage);
        $this->assertEditableBy($request->user()->id, $bookPage);
        $data = $request->validated();
        $group = DB::transaction(function () use ($request, $bookPage, $data): BookBlockGroup {
            $group = $bookPage->groups()->create([
                'created_by' => $request->user()->id,
                'type' => $data['type'],
                'role' => $data['role'] ?? 'content',
                'visibility' => $data['visibility'] ?? 'private',
                'sort_order' => $data['sort_order'] ?? 0,
            ]);
            $block = $group->versions()->create([
                'created_by' => $request->user()->id,
                'title' => $data['title'] ?? null,
                'content' => $data['content'] ?? null,
                'payload' => $data['payload'] ?? null,
                'search_text' => $data['search_text'] ?? null,
            ]);
            $group->update(['master_block_id' => $block->id]);

            return $group;
        });

        return new BookBlockGroupResource($group->load('masterBlock'));
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): void
    {
        abort(404);
    }

    public function storeVersion(StoreBookBlockVersionRequest $request, Scope $scope, Book $book, BookPage $bookPage, BookBlockGroup $group): JsonResponse
    {
        $this->assertPageInScope($scope, $book, $bookPage); abort_unless($group->page_id === $bookPage->id, 404);
        $this->assertEditableBy($request->user()->id, $bookPage);
        DB::transaction(function () use ($request, $group): void {
            $locked = BookBlockGroup::query()->lockForUpdate()->findOrFail($group->id);
            $number = ((int) $locked->versions()->max('version_number')) + 1;
            $data = $request->validated();
            $block = $locked->versions()->create([...$data, 'version_number' => $number, 'created_by' => $request->user()->id, 'published_at' => ($data['status'] ?? null) === 'published' ? now() : null]);
            $locked->update(['master_block_id' => $block->id]);
        });
        return (new BookBlockGroupResource($group->fresh()->load('masterBlock')))->response()->setStatusCode(201);
    }

    public function reorder(ReorderBookBlocksRequest $request, Scope $scope, Book $book, BookPage $bookPage): JsonResponse
    {
        $this->assertPageInScope($scope, $book, $bookPage);
        $this->assertEditableBy($request->user()->id, $bookPage);
        $items = collect($request->validated('items'));
        abort_unless($bookPage->groups()->whereIn('id', $items->pluck('id'))->count() === $items->count(), 422, 'Все блоки должны принадлежать этой странице.');
        DB::transaction(function () use ($items): void {
            foreach ($items as $item) BookBlockGroup::query()->whereKey($item['id'])->update(['sort_order' => $item['sort_order']]);
        });

        return response()->json(['ok' => true]);
    }

    /**
     * Update the specified resource in storage.
     */
    private function assertPageInScope(Scope $scope, Book $book, BookPage $bookPage): void
    {
        abort_unless($book->scope_id === $scope->id && $bookPage->book_id === $book->id, 404);
    }

    private function assertEditableBy(string $userId, BookPage $bookPage): void
    {
        abort_unless($bookPage->editing_by === $userId, 423, 'Сначала захватите страницу для редактирования.');
    }
}
