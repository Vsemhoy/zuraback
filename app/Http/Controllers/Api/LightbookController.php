<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ContentFilterRequest;
use App\Http\Requests\Api\StoreLightbookRequest;
use App\Http\Requests\Api\UpdateLightbookRequest;
use App\Http\Resources\LightbookResource;
use App\Models\ActivityLog;
use App\Models\Book;
use App\Models\Project;
use App\Models\Scope;
use App\Models\User;
use App\Services\ContentStructureService;
use App\Services\ContractorAccessService;
use App\Services\ContractorContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class LightbookController extends Controller
{
    public function __construct(private readonly ContractorAccessService $access, private readonly ContractorContext $context, private readonly ContentStructureService $structure) {}

    public function index(ContentFilterRequest $request, Scope $scope): AnonymousResourceCollection
    {
        $actor = $this->context->actor($request);
        $query = $this->access->constrainBooks($scope->books()->where('kind', 'lightbook')->getQuery(), $actor, $scope);
        $this->structure->filter($query, $request, $scope);
        if ($request->filled('q')) {
            $query->where(fn ($q) => $q->where('title', 'like', '%'.$request->query('q').'%')->orWhere('description', 'like', '%'.$request->query('q').'%'));
        }
        $items = $query
            ->with(['page:id,book_id,title,updated_at', 'project:id,title,key,color', 'creator:id,name'])
            ->latest('updated_at')->orderByDesc('id')->get();

        return LightbookResource::collection($items);
    }

    public function store(StoreLightbookRequest $request, Scope $scope): LightbookResource
    {
        $data = $request->validated();
        $actor = $this->context->actor($request);
        $this->assertProject($scope, $data['project_id'] ?? null, $actor);
        $this->structure->assertReferences($scope, $actor, $data);
        $lightbook = DB::transaction(function () use ($scope, $actor, $data): Book {
            $lightbook = $scope->books()->create([
                ...$data,
                'kind' => 'lightbook',
                'space_id' => null,
                'structure_mode' => 'flat',
                'created_by' => $actor->id,
            ]);
            $lightbook->pages()->create([
                'created_by' => $actor->id,
                'title' => $data['title'],
                'visibility' => $data['visibility'] ?? 'private',
                'sort_order' => 0,
            ]);

            return $lightbook;
        });

        return new LightbookResource($this->load($lightbook));
    }

    public function show(Request $request, Scope $scope, Book $book): LightbookResource
    {
        $this->authorize($request, $scope, $book);

        return new LightbookResource($this->load($book));
    }

    public function update(UpdateLightbookRequest $request, Scope $scope, Book $book): LightbookResource
    {
        $this->authorize($request, $scope, $book);
        $data = $request->validated();
        $this->assertProject($scope, array_key_exists('project_id', $data) ? $data['project_id'] : $book->project_id, $this->context->actor($request));
        $this->structure->assertReferences($scope, $this->context->actor($request), $data, $book);
        DB::transaction(function () use ($book, $data): void {
            $book->update($data);
            $book->page()->update(array_intersect_key($data, array_flip(['title', 'visibility'])));
        });

        return new LightbookResource($this->load($book->fresh()));
    }

    public function destroy(Request $request, Scope $scope, Book $book): Response
    {
        $this->authorize($request, $scope, $book);
        DB::transaction(function () use ($request, $scope, $book): void {
            $book->pages()->update(['editing_by' => null, 'editing_started_at' => null]);
            $book->delete();
            ActivityLog::query()->create([
                'scope_id' => $scope->id,
                'actor_id' => $this->context->actor($request)->id,
                'subject_type' => 'book',
                'subject_id' => $book->id,
                'action' => 'lightbook.deleted',
                'before' => ['title' => $book->title],
                'context' => ['soft_deleted' => true, ...$this->context->auditMetadata($request)],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        });

        return response()->noContent();
    }

    private function authorize(Request $request, Scope $scope, Book $book): void
    {
        abort_unless($book->scope_id === $scope->id && $book->kind === 'lightbook', 404);
        abort_unless($this->access->canAccessBook($this->context->actor($request), $scope, $book->loadMissing('project')), 403);
    }

    private function assertProject(Scope $scope, ?string $projectId, User $actor): void
    {
        if ($projectId === null) {
            return;
        }
        $project = Project::query()->where('scope_id', $scope->id)->findOrFail($projectId);
        abort_unless($this->access->canAccessProject($actor, $scope, $project, 'book.view'), 403);
    }

    private function load(Book $book): Book
    {
        return $book->load(['creator:id,name', 'project:id,title,key,color', 'page' => fn ($query) => $query
            ->with(['editor:id,name,username', 'groups.masterBlock'])->withCount('versions')]);
    }
}
