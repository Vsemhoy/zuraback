<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreBookRequest;
use App\Http\Requests\Api\UpdateBookRequest;
use App\Http\Resources\BookResource;
use App\Models\Book;
use App\Models\Project;
use App\Models\Scope;
use App\Models\User;
use App\Services\ContractorAccessService;
use App\Services\ContractorContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BookController extends Controller
{
    public function __construct(private readonly ContractorAccessService $access, private readonly ContractorContext $context) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, Scope $scope): AnonymousResourceCollection
    {
        $books = $this->access->constrainBooks($scope->books()->getQuery(), $this->context->actor($request), $scope);

        return BookResource::collection($books->with('project:id,title,key,color')->withCount('pages')->latest()->paginate());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreBookRequest $request, Scope $scope): BookResource
    {
        $data = $request->validated();
        if (isset($data['space_id'])) {
            abort_unless($scope->bookSpaces()->whereKey($data['space_id'])->exists(), 422, 'Space belongs to another scope.');
        }
        $this->assertProject($scope, $data['project_id'] ?? null, $this->context->actor($request));
        $book = $scope->books()->create([...$data, 'created_by' => $this->context->actor($request)->id]);

        return new BookResource($book->load('project:id,title,key,color'));
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Scope $scope, Book $book): BookResource
    {
        abort_unless($book->scope_id === $scope->id, 404);
        abort_unless($this->access->canAccessBook($this->context->actor($request), $scope, $book->load('project')), 403);

        return new BookResource($book->load('project:id,title,key,color')->loadCount('pages'));
    }

    public function update(UpdateBookRequest $request, Scope $scope, Book $book): BookResource
    {
        abort_unless($book->scope_id === $scope->id, 404);
        $data = $request->validated();
        $targetScope = isset($data['scope_id']) ? Scope::query()->findOrFail($data['scope_id']) : $scope;
        abort_unless($targetScope->owner_id === $request->user()->id || $targetScope->members()->where('user_id', $request->user()->id)->where('is_active', true)->exists(), 403);
        if (isset($data['space_id'])) {
            abort_unless($targetScope->bookSpaces()->whereKey($data['space_id'])->exists(), 422, 'Space belongs to another scope.');
        }
        if ($targetScope->id !== $scope->id) {
            $data['space_id'] = null;
            $data['project_id'] = null;
        }
        $this->assertProject($targetScope, $data['project_id'] ?? $book->project_id, $this->context->actor($request));
        $book->update($data);

        return new BookResource($book->fresh()->load('project:id,title,key,color')->loadCount('pages'));
    }

    private function assertProject(Scope $scope, ?string $projectId, User $actor): void
    {
        if ($projectId === null) {
            return;
        }
        $project = Project::query()->where('scope_id', $scope->id)->findOrFail($projectId);
        abort_unless($this->access->allows($actor, $scope, 'task.view', $project), 403, 'This project is outside the contractor access boundary.');
    }
}
