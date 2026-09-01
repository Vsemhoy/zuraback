<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreProjectRequest;
use App\Http\Requests\Api\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\Scope;
use App\Services\ContractorAccessService;
use App\Services\ContractorContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
            $query->orderBy('sort_order')->orderBy('title')->get()
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreProjectRequest $request, Scope $scope): ProjectResource
    {
        $data = $request->validated();
        $data['sort_order'] ??= ((int) $scope->projects()->max('sort_order')) + 1;
        $project = $scope->projects()->create([...$data, 'created_by' => $this->context->actor($request)->id]);

        return new ProjectResource($project);
    }

    /**
     * Display the specified resource.
     */
    public function show(Scope $scope, Project $project): ProjectResource
    {
        abort_unless($project->scope_id === $scope->id, 404);

        return new ProjectResource($project);
    }

    public function update(UpdateProjectRequest $request, Scope $scope, Project $project): ProjectResource
    {
        abort_unless($project->scope_id === $scope->id, 404);
        $project->update($request->validated());

        return new ProjectResource($project->fresh());
    }
}
