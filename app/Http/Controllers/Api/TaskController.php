<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\Scope;
use App\Models\Task;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TaskController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Scope $scope): AnonymousResourceCollection
    {
        return TaskResource::collection($scope->tasks()->with(['project:id,title', 'assignee:id,name'])->latest()->paginate());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreTaskRequest $request, Scope $scope): TaskResource
    {
        $data = $request->validated();
        $this->assertReferencesBelongToScope($scope, $data);
        $task = $scope->tasks()->create([...$data, 'created_by' => $request->user()->id]);

        return new TaskResource($task);
    }

    /**
     * Display the specified resource.
     */
    public function show(Scope $scope, Task $task): TaskResource
    {
        abort_unless($task->scope_id === $scope->id, 404);

        return new TaskResource($task->load(['project:id,title', 'assignee:id,name']));
    }

    /** @param array<string, mixed> $data */
    private function assertReferencesBelongToScope(Scope $scope, array $data): void
    {
        foreach (['project_id' => 'projects', 'parent_id' => 'tasks', 'responsibility_area_id' => 'responsibilityAreas'] as $key => $relation) {
            if (isset($data[$key])) {
                abort_unless($scope->{$relation}()->whereKey($data[$key])->exists(), 422, "Invalid {$key} for this scope.");
            }
        }
    }
}
