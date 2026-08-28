<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreProjectRequest;
use App\Http\Requests\Api\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\Scope;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProjectController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Scope $scope): AnonymousResourceCollection
    {
        return ProjectResource::collection($scope->projects()->latest()->paginate());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreProjectRequest $request, Scope $scope): ProjectResource
    {
        $project = $scope->projects()->create([...$request->validated(), 'created_by' => $request->user()->id]);

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
