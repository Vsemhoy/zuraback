<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreTaskRelationRequest;
use App\Models\ActivityLog;
use App\Models\EntityLink;
use App\Models\Scope;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskRelationController extends Controller
{
    public function index(Scope $scope, Task $task): JsonResponse
    {
        $this->assertTask($scope, $task);
        $links = EntityLink::query()->where('scope_id', $scope->id)
            ->where('source_type', 'task')->where('target_type', 'task')
            ->where(fn ($query) => $query->where('source_id', $task->id)->orWhere('target_id', $task->id))
            ->latest()->get();
        $taskIds = $links->flatMap(fn (EntityLink $link) => [$link->source_id, $link->target_id])->unique()->reject(fn ($id) => $id === $task->id);
        $tasks = Task::query()->whereIn('id', $taskIds)->with('project:id,title,key')->get()->keyBy('id');

        return response()->json(['data' => $links->map(fn (EntityLink $link) => $this->present($link, $task, $tasks))->values()]);
    }

    public function store(StoreTaskRelationRequest $request, Scope $scope, Task $task): JsonResponse
    {
        $this->assertTask($scope, $task);
        $data = $request->validated();
        $other = $scope->tasks()->where('task_key', strtoupper($data['task_key']))->firstOrFail();
        abort_if($other->id === $task->id, 422, 'A task cannot be related to itself.');

        $reverse = $data['relation'] === 'blocked_by';
        $relation = $reverse ? 'blocks' : $data['relation'];
        $source = $reverse ? $other : $task;
        $target = $reverse ? $task : $other;
        if (in_array($relation, ['related', 'duplicate'], true) && strcmp($source->id, $target->id) > 0) {
            [$source, $target] = [$target, $source];
        }
        $link = EntityLink::query()->firstOrCreate([
            'scope_id' => $scope->id, 'source_type' => 'task', 'source_id' => $source->id,
            'target_type' => 'task', 'target_id' => $target->id, 'relation' => $relation,
        ], ['created_by' => $request->user()->id]);
        abort_unless($link->wasRecentlyCreated, 422, 'This relation already exists.');
        ActivityLog::query()->create([
            'scope_id' => $scope->id, 'actor_id' => $request->user()->id,
            'subject_type' => 'task', 'subject_id' => $task->id, 'action' => 'task.relation_created',
            'after' => ['link_id' => $link->id, 'relation' => $data['relation'], 'task_id' => $other->id, 'task_key' => $other->task_key],
            'context' => ['link_id' => $link->id], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['data' => $this->present($link, $task, collect([$other->id => $other->load('project:id,title,key')]))], 201);
    }

    public function destroy(Request $request, Scope $scope, Task $task, EntityLink $link): JsonResponse
    {
        $this->assertTask($scope, $task);
        abort_unless($link->scope_id === $scope->id && $link->source_type === 'task' && $link->target_type === 'task' && in_array($task->id, [$link->source_id, $link->target_id], true), 404);
        $snapshot = $link->only(['id', 'source_id', 'target_id', 'relation']);
        $link->delete();
        ActivityLog::query()->create([
            'scope_id' => $scope->id, 'actor_id' => $request->user()->id,
            'subject_type' => 'task', 'subject_id' => $task->id, 'action' => 'task.relation_deleted',
            'before' => $snapshot, 'context' => ['link_id' => $snapshot['id']],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json(null, 204);
    }

    private function assertTask(Scope $scope, Task $task): void { abort_unless($task->scope_id === $scope->id, 404); }

    private function present(EntityLink $link, Task $current, $tasks): array
    {
        $outbound = $link->source_id === $current->id;
        $otherId = $outbound ? $link->target_id : $link->source_id;
        $relation = $link->relation === 'blocks' && ! $outbound ? 'blocked_by' : $link->relation;
        return ['id' => $link->id, 'relation' => $relation, 'task' => $tasks->get($otherId), 'created_at' => $link->created_at];
    }
}
