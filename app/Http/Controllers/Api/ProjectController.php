<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreProjectRequest;
use App\Http\Requests\Api\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\Scope;
use App\Services\ContractorAccessService;
use App\Services\ContractorContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class ProjectController extends Controller
{
    public function __construct(
        private readonly ContractorAccessService $access,
        private readonly ContractorContext $context,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, Scope $scope): AnonymousResourceCollection
    {
        $query = $this->access->constrainProjects($scope->projects()->getQuery(), $this->context->actor($request), $scope);

        return ProjectResource::collection(
            $query->with('creator:id,name,username')->withCount(['tasks', 'books'])->orderBy('sort_order')->orderBy('title')->get()
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreProjectRequest $request, Scope $scope): ProjectResource
    {
        $actor = $this->context->actor($request);
        if ($request->user()->isAgent()) {
            abort_unless($this->access->canAccessUnprojected($actor, $scope), 403, 'Creating projects requires all-project access in the scope.');
        }
        $data = $request->validated();
        $data['visibility'] ??= 'private';
        $data['sort_order'] ??= ((int) $scope->projects()->max('sort_order')) + 1;
        $project = $scope->projects()->create([...$data, 'created_by' => $actor->id]);

        return new ProjectResource($project->load('creator:id,name,username')->loadCount(['tasks', 'books']));
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Scope $scope, Project $project): ProjectResource
    {
        abort_unless($this->access->canAccessProject($this->context->actor($request), $scope, $project), 404);

        return new ProjectResource($project->load(['books:id,scope_id,project_id,title', 'creator:id,name,username'])->loadCount(['tasks', 'books']));
    }

    public function update(UpdateProjectRequest $request, Scope $scope, Project $project): ProjectResource
    {
        $actor = $this->context->actor($request);
        abort_unless($this->access->canAccessProject($actor, $scope, $project, 'task.update'), 404);
        $data = $request->validated();
        if (array_key_exists('visibility', $data) && $data['visibility'] !== $project->visibility) {
            abort_unless($project->created_by === $actor->id || $scope->owner_id === $actor->id, 403, 'Only the project creator or scope owner can change project privacy.');
        }
        $project->update($data);

        return new ProjectResource($project->fresh()->load(['books:id,scope_id,project_id,title', 'creator:id,name,username'])->loadCount(['tasks', 'books']));
    }

    public function reorder(Request $request, Scope $scope): AnonymousResourceCollection
    {
        $data = $request->validate([
            'project_ids' => ['required', 'array'],
            'project_ids.*' => ['required', 'ulid', 'distinct'],
        ]);
        $actor = $this->context->actor($request);
        $projects = $this->access->constrainProjects(
            $scope->projects()->getQuery()->whereIn('id', $data['project_ids']),
            $actor,
            $scope,
        )->get()->keyBy('id');

        abort_unless($projects->count() === count($data['project_ids']), 404);

        DB::transaction(function () use ($data, $projects): void {
            foreach ($data['project_ids'] as $index => $projectId) {
                $projects->get($projectId)->update(['sort_order' => ($index + 1) * 1000]);
            }
        });

        return $this->index($request, $scope);
    }

    public function destroy(Request $request, Scope $scope, Project $project): Response
    {
        abort_unless($project->scope_id === $scope->id, 404);
        abort_unless($this->access->allows($this->context->actor($request), $scope, 'project.delete', $project), 403, 'The project.delete capability is required.');

        DB::transaction(function () use ($request, $scope, $project): void {
            $counts = ['tasks' => $project->tasks()->count(), 'books' => $project->books()->count()];
            $project->tasks()->update(['project_id' => null]);
            $project->books()->update(['project_id' => null]);
            $project->members()->update(['is_active' => false]);
            $project->delete();
            ActivityLog::query()->create([
                'scope_id' => $scope->id,
                'actor_id' => $this->context->actor($request)->id,
                'subject_type' => 'project',
                'subject_id' => $project->id,
                'action' => 'project.deleted',
                'before' => ['title' => $project->title, 'key' => $project->key, ...$counts],
                'context' => $this->context->auditMetadata($request),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        });

        return response()->noContent();
    }
}
