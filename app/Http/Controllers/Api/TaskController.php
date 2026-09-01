<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\MoveTaskRequest;
use App\Http\Requests\Api\StoreTaskRequest;
use App\Http\Requests\Api\UpdateTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\ActivityLog;
use App\Models\EntityLink;
use App\Models\Project;
use App\Models\Scope;
use App\Models\Task;
use App\Services\TaskKeyService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class TaskController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Scope $scope): AnonymousResourceCollection
    {
        return TaskResource::collection($scope->tasks()->with(['project:id,title,key,color', 'assignee:id,name'])->orderBy('sort_order')->orderBy('created_at')->paginate());
    }

    public function search(Request $request, Scope $scope): AnonymousResourceCollection
    {
        $query = trim((string) $request->query('q'));
        abort_if(mb_strlen($query) < 2, 422, 'Search query must contain at least two characters.');

        return TaskResource::collection($scope->tasks()
            ->with(['project:id,title,key,color', 'assignee:id,name'])
            ->where(fn ($builder) => $builder->where('task_key', 'like', strtoupper($query).'%')->orWhere('title', 'like', '%'.$query.'%'))
            ->limit(20)->get());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreTaskRequest $request, Scope $scope, TaskKeyService $keys): TaskResource
    {
        $data = $request->validated();
        $this->assertReferencesBelongToScope($scope, $data);
        if (! empty($data['parent_id'])) {
            $parent = $scope->tasks()->findOrFail($data['parent_id']);
            abort_if($parent->parent_id !== null, 422, 'Only one level of true subtasks is supported.');
        }

        abort_if(($data['status'] ?? null) === 'blocked', 422, 'Create the task first, then use the blocker endpoint.');
        $task = DB::transaction(function () use ($request, $scope, $keys, $data): Task {
            $project = isset($data['project_id']) ? Project::query()->findOrFail($data['project_id']) : null;
            $identity = $keys->reserve($scope, $project);

            return $scope->tasks()->create([...$data, ...$identity, 'created_by' => $request->user()->id]);
        });

        return new TaskResource($task->load(['project:id,title,key,color', 'assignee:id,name']));
    }

    /**
     * Display the specified resource.
     */
    public function show(Scope $scope, Task $task): TaskResource
    {
        abort_unless($task->scope_id === $scope->id, 404);

        return new TaskResource($task->load(['project:id,title,key,color', 'assignee:id,name', 'checklistItems.assignee:id,name', 'checklistItems.completedBy:id,name', 'blockers.responsibleUser:id,name', 'blockers.blockedBy:id,name', 'blockers.resolvedBy:id,name', 'children:id,scope_id,project_id,parent_id,task_key,title,status,priority,due_at,assignee_id']));
    }

    public function update(UpdateTaskRequest $request, Scope $scope, Task $task): TaskResource
    {
        abort_unless($task->scope_id === $scope->id, 404);
        $data = $request->validated();
        $this->assertReferencesBelongToScope($scope, $data);

        if (isset($data['status'])) {
            abort_if($data['status'] === 'blocked' && $task->status !== 'blocked', 422, 'Use the blocker endpoint to block a task.');
            abort_if($task->status === 'blocked' && $data['status'] !== 'blocked' && $task->blockers()->whereNull('resolved_at')->exists(), 422, 'Resolve the active blocker before changing task status.');
        }

        if (array_key_exists('parent_id', $data) && $data['parent_id']) {
            abort_if($data['parent_id'] === $task->id, 422, 'A task cannot be its own parent.');
            $parent = $scope->tasks()->findOrFail($data['parent_id']);
            abort_if($parent->parent_id !== null, 422, 'Only one level of true subtasks is supported.');
            abort_if($task->children()->exists(), 422, 'A task with subtasks cannot become a subtask.');
        }

        if (($data['status'] ?? null) === 'done' && $task->completed_at === null) {
            $data['completed_at'] = now();
        } elseif (isset($data['status']) && $data['status'] !== 'done') {
            $data['completed_at'] = null;
        }

        $task->update($data);

        return new TaskResource($task->fresh()->load(['project:id,title,key,color', 'assignee:id,name', 'checklistItems.assignee:id,name', 'checklistItems.completedBy:id,name', 'blockers.responsibleUser:id,name', 'blockers.blockedBy:id,name', 'blockers.resolvedBy:id,name', 'children:id,scope_id,project_id,parent_id,task_key,title,status,priority,due_at,assignee_id']));
    }

    public function move(MoveTaskRequest $request, Scope $scope, Task $task): TaskResource
    {
        abort_unless($task->scope_id === $scope->id, 404);
        abort_if($task->status === 'blocked' || $task->blockers()->whereNull('resolved_at')->exists(), 422, 'Resolve the active blocker before moving this task.');
        $targetStatus = $request->validated('status');
        $targetIndex = $request->integer('target_index');
        $before = ['status' => $task->status, 'sort_order' => $task->sort_order];

        DB::transaction(function () use ($request, $scope, $task, $targetStatus, $targetIndex, $before): void {
            $sourceStatus = $task->status;
            $groups = Task::query()->where('scope_id', $scope->id)
                ->whereIn('status', array_values(array_unique([$sourceStatus, $targetStatus])))
                ->orderBy('id')->lockForUpdate()->get()->groupBy('status');

            $source = ($groups->get($sourceStatus) ?? collect())->reject(fn (Task $item) => $item->id === $task->id)->sortBy(fn (Task $item) => sprintf('%012d-%s', $item->sort_order, $item->id))->values();
            $target = $sourceStatus === $targetStatus
                ? $source
                : ($groups->get($targetStatus) ?? collect())->reject(fn (Task $item) => $item->id === $task->id)->sortBy(fn (Task $item) => sprintf('%012d-%s', $item->sort_order, $item->id))->values();
            $target->splice(min($targetIndex, $target->count()), 0, [$task]);

            if ($sourceStatus !== $targetStatus) {
                foreach ($source as $index => $item) {
                    $item->update(['sort_order' => ($index + 1) * 1000]);
                }
            }
            foreach ($target as $index => $item) {
                $changes = ['sort_order' => ($index + 1) * 1000];
                if ($item->id === $task->id) {
                    $changes['status'] = $targetStatus;
                    $changes['completed_at'] = $targetStatus === 'done' ? ($task->completed_at ?? now()) : null;
                }
                $item->update($changes);
            }

            ActivityLog::query()->create([
                'scope_id' => $scope->id,
                'actor_id' => $request->user()->id,
                'subject_type' => $task->getMorphClass(),
                'subject_id' => $task->id,
                'action' => 'task.moved',
                'before' => $before,
                'after' => ['status' => $targetStatus, 'sort_order' => ($target->search(fn (Task $item) => $item->id === $task->id) + 1) * 1000],
                'context' => ['target_index' => $targetIndex],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        });

        return new TaskResource($task->fresh()->load(['project:id,title,key,color', 'assignee:id,name']));
    }

    public function detach(Request $request, Scope $scope, Task $task): TaskResource
    {
        abort_unless($task->scope_id === $scope->id, 404);
        abort_if($task->parent_id === null, 422, 'The task is not a subtask.');

        DB::transaction(function () use ($request, $scope, $task): void {
            $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->id);
            $formerParentId = $lockedTask->parent_id;
            $lockedTask->update(['parent_id' => null]);
            [$sourceId, $targetId] = strcmp($lockedTask->id, $formerParentId) < 0
                ? [$lockedTask->id, $formerParentId] : [$formerParentId, $lockedTask->id];
            EntityLink::query()->firstOrCreate([
                'scope_id' => $scope->id, 'source_type' => $lockedTask->getMorphClass(), 'source_id' => $sourceId,
                'target_type' => $lockedTask->getMorphClass(), 'target_id' => $targetId, 'relation' => 'related',
            ], ['created_by' => $request->user()->id, 'note' => 'Created when the subtask was extracted.']);
            ActivityLog::query()->create([
                'scope_id' => $scope->id, 'actor_id' => $request->user()->id,
                'subject_type' => $lockedTask->getMorphClass(), 'subject_id' => $lockedTask->id,
                'action' => 'task.detached', 'before' => ['parent_id' => $formerParentId], 'after' => ['parent_id' => null],
                'context' => ['former_parent_id' => $formerParentId], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
            ]);
        });

        return new TaskResource($task->fresh()->load(['project:id,title,key,color', 'assignee:id,name']));
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
