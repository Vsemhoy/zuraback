<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreBookBlockGroupRequest;
use App\Http\Resources\BookBlockGroupResource;
use App\Models\Book;
use App\Models\BookBlockGroup;
use App\Models\BookPage;
use App\Models\Scope;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

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

    /**
     * Update the specified resource in storage.
     */
    private function assertPageInScope(Scope $scope, Book $book, BookPage $bookPage): void
    {
        abort_unless($book->scope_id === $scope->id && $bookPage->book_id === $book->id, 404);
    }
}
