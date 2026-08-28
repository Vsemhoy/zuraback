<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreTaskChecklistItemRequest;
use App\Http\Requests\Api\UpdateTaskChecklistItemRequest;
use App\Http\Resources\TaskChecklistItemResource;
use App\Models\ActivityLog;
use App\Models\Scope;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\Project;
use App\Services\TaskKeyService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class TaskChecklistItemController extends Controller
{
    public function index(Scope $scope, Task $task): AnonymousResourceCollection
    {
        $this->assertTask($scope, $task);

        return TaskChecklistItemResource::collection($task->checklistItems()->with(['assignee:id,name', 'completedBy:id,name'])->get());
    }

    public function store(StoreTaskChecklistItemRequest $request, Scope $scope, Task $task): TaskChecklistItemResource
    {
        $this->assertTask($scope, $task);
        $data = $request->validated();
        $data['sort_order'] ??= ((int) $task->checklistItems()->max('sort_order')) + 1;
        $item = $task->checklistItems()->create([...$data, 'created_by' => $request->user()->id]);

        return new TaskChecklistItemResource($item->load(['assignee:id,name', 'completedBy:id,name']));
    }

    public function update(UpdateTaskChecklistItemRequest $request, Scope $scope, Task $task, TaskChecklistItem $item): TaskChecklistItemResource
    {
        $this->assertItem($scope, $task, $item);
        $data = $request->validated();

        DB::transaction(function () use ($request, $scope, $item, &$data): void {
            if (array_key_exists('is_completed', $data)) {
                $complete = (bool) $data['is_completed'];
                unset($data['is_completed']);
                $wasComplete = $item->completed_at !== null;
                $data['completed_at'] = $complete ? now() : null;
                $data['completed_by'] = $complete ? $request->user()->id : null;

                if ($complete !== $wasComplete) {
                    ActivityLog::query()->create([
                        'scope_id' => $scope->id,
                        'actor_id' => $request->user()->id,
                        'subject_type' => $item->getMorphClass(),
                        'subject_id' => $item->id,
                        'action' => $complete ? 'checklist_item.completed' : 'checklist_item.reopened',
                        'before' => ['completed_at' => $item->completed_at?->toISOString(), 'completed_by' => $item->completed_by],
                        'after' => ['completed_at' => $data['completed_at']?->toISOString(), 'completed_by' => $data['completed_by']],
                        'context' => ['task_id' => $item->task_id],
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                    ]);
                }
            }

            $item->update($data);
        });

        return new TaskChecklistItemResource($item->fresh()->load(['assignee:id,name', 'completedBy:id,name']));
    }

    public function destroy(Scope $scope, Task $task, TaskChecklistItem $item): Response
    {
        $this->assertItem($scope, $task, $item);
        $item->delete();

        return response()->noContent();
    }

    public function convertToSubtask(Scope $scope, Task $task, TaskChecklistItem $item, TaskKeyService $keys): \App\Http\Resources\TaskResource
    {
        $this->assertItem($scope, $task, $item);
        abort_if($task->parent_id !== null, 422, 'Only one level of true subtasks is supported.');

        $subtask = DB::transaction(function () use ($scope, $task, $item, $keys): Task {
            $lockedItem = TaskChecklistItem::query()->lockForUpdate()->findOrFail($item->id);
            $project = $task->project_id ? Project::query()->findOrFail($task->project_id) : null;
            $identity = $keys->reserve($scope, $project);
            $subtask = $scope->tasks()->create([
                ...$identity, 'project_id' => $task->project_id, 'parent_id' => $task->id,
                'created_by' => $lockedItem->created_by, 'assignee_id' => $lockedItem->assignee_id,
                'title' => $lockedItem->title, 'due_at' => $lockedItem->due_at,
                'status' => $lockedItem->completed_at ? 'done' : 'todo', 'completed_at' => $lockedItem->completed_at,
            ]);
            ActivityLog::query()->create([
                'scope_id' => $scope->id, 'actor_id' => request()->user()->id,
                'subject_type' => $subtask->getMorphClass(), 'subject_id' => $subtask->id,
                'action' => 'checklist_item.converted_to_subtask',
                'before' => $lockedItem->only(['id', 'title', 'assignee_id', 'due_at', 'completed_at']),
                'after' => $subtask->only(['id', 'task_key', 'parent_id', 'status']),
                'context' => ['checklist_item_id' => $lockedItem->id, 'parent_task_id' => $task->id],
                'ip_address' => request()->ip(), 'user_agent' => request()->userAgent(),
            ]);
            $lockedItem->delete();
            return $subtask;
        });

        return new \App\Http\Resources\TaskResource($subtask->load(['project:id,title,key', 'assignee:id,name']));
    }

    private function assertTask(Scope $scope, Task $task): void
    {
        abort_unless($task->scope_id === $scope->id, 404);
    }

    private function assertItem(Scope $scope, Task $task, TaskChecklistItem $item): void
    {
        $this->assertTask($scope, $task);
        abort_unless($item->task_id === $task->id, 404);
    }
}
