<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreBookPageRequest;
use App\Http\Resources\BookPageResource;
use App\Models\Book;
use App\Models\BookPage;
use App\Models\Scope;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BookPageController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Scope $scope, Book $book): AnonymousResourceCollection
    {
        $this->assertBookInScope($scope, $book);

        return BookPageResource::collection($book->pages()->withCount('groups')->orderBy('sort_order')->paginate());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreBookPageRequest $request, Scope $scope, Book $book): BookPageResource
    {
        $this->assertBookInScope($scope, $book);
        $data = $request->validated();
        if (isset($data['parent_id'])) {
            abort_unless($book->pages()->whereKey($data['parent_id'])->exists(), 422, 'Parent page belongs to another book.');
        }
        $page = $book->pages()->create([...$data, 'created_by' => $request->user()->id]);

        return new BookPageResource($page);
    }

    /**
     * Display the specified resource.
     */
    public function show(Scope $scope, Book $book, BookPage $bookPage): BookPageResource
    {
        $this->assertBookInScope($scope, $book);
        abort_unless($bookPage->book_id === $book->id, 404);

        return new BookPageResource($bookPage->load(['groups.masterBlock']));
    }

    private function assertBookInScope(Scope $scope, Book $book): void
    {
        abort_unless($book->scope_id === $scope->id, 404);
    }
}
