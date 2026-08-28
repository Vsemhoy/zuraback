<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreBookRequest;
use App\Http\Requests\Api\UpdateBookRequest;
use App\Http\Resources\BookResource;
use App\Models\Book;
use App\Models\Scope;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BookController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Scope $scope): AnonymousResourceCollection
    {
        return BookResource::collection($scope->books()->withCount('pages')->latest()->paginate());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreBookRequest $request, Scope $scope): BookResource
    {
        $data = $request->validated();
        if (isset($data['space_id'])) abort_unless($scope->bookSpaces()->whereKey($data['space_id'])->exists(), 422, 'Space belongs to another scope.');
        $book = $scope->books()->create([...$data, 'created_by' => $request->user()->id]);

        return new BookResource($book);
    }

    /**
     * Display the specified resource.
     */
    public function show(Scope $scope, Book $book): BookResource
    {
        abort_unless($book->scope_id === $scope->id, 404);

        return new BookResource($book->loadCount('pages'));
    }

    public function update(UpdateBookRequest $request, Scope $scope, Book $book): BookResource
    {
        abort_unless($book->scope_id === $scope->id, 404); $data = $request->validated();
        $targetScope = isset($data['scope_id']) ? Scope::query()->findOrFail($data['scope_id']) : $scope;
        abort_unless($targetScope->members()->where('user_id', $request->user()->id)->exists(), 403);
        if (isset($data['space_id'])) abort_unless($targetScope->bookSpaces()->whereKey($data['space_id'])->exists(), 422, 'Space belongs to another scope.');
        if ($targetScope->id !== $scope->id) $data['space_id'] = null;
        $book->update($data); return new BookResource($book->fresh()->loadCount('pages'));
    }
}
