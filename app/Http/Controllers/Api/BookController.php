<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreBookRequest;
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
        $book = $scope->books()->create([...$request->validated(), 'created_by' => $request->user()->id]);

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
}
