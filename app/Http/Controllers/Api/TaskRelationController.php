<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreTaskRelationRequest;
use App\Models\ActivityLog;
use App\Models\EntityLink;
use App\Models\Scope;
use App\Models\Task;
use App\Services\ContractorAccessService;
use App\Services\ContractorContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskRelationController extends Controller
{
    public function __construct(
        private readonly ContractorAccessService $access,
        private readonly ContractorContext $context,
    ) {}

    public function index(Request $request, Scope $scope, Task $task): JsonResponse
    {
        $this->assertTask($scope, $task);
        $links = EntityLink::query()->where('scope_id', $scope->id)
            ->where('source_type', 'task')->where('target_type', 'task')
            ->where(fn ($query) => $query->where('source_id', $task->id)->orWhere('target_id', $task->id))
            ->latest()->get();
        $taskIds = $links->flatMap(fn (EntityLink $link) => [$link->source_id, $link->target_id])->unique()->reject(fn ($id) => $id === $task->id);
        $tasks = $this->access->constrainTasks(Task::query()->whereIn('id', $taskIds), $this->context->actor($request), $scope)
            ->with('project:id,title,key')->get()->keyBy('id');

        return response()->json(['data' => $links->map(fn (EntityLink $link) => $this->present($link, $task, $tasks))->filter(fn (array $item) => $item['task'] !== null)->values()]);
    }

    public function store(StoreTaskRelationRequest $request, Scope $scope, Task $task): JsonResponse
    {
        $this->assertTask($scope, $task);
        $data = $request->validated();
        $other = $scope->tasks()->with('project')->where('task_key', strtoupper($data['task_key']))->firstOrFail();
        abort_if($other->id === $task->id, 422, 'A task cannot be related to itself.');
        abort_if($other->project_id === null && ! $this->access->canAccessUnprojected($this->context->actor($request), $scope), 403, 'The related task is outside the contractor access boundary.');
        abort_unless($this->access->allows($this->context->actor($request), $scope, 'task.view', $other->project), 403, 'The related task is outside the contractor access boundary.');

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
        ], ['created_by' => $this->context->actor($request)->id]);
        abort_unless($link->wasRecentlyCreated, 422, 'This relation already exists.');
        ActivityLog::query()->create([
            'scope_id' => $scope->id, 'actor_id' => $this->context->actor($request)->id,
            'subject_type' => 'task', 'subject_id' => $task->id, 'action' => 'task.relation_created',
            'after' => ['link_id' => $link->id, 'relation' => $data['relation'], 'task_id' => $other->id, 'task_key' => $other->task_key],
            'context' => ['link_id' => $link->id, ...$this->context->auditMetadata($request)], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
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
            'scope_id' => $scope->id, 'actor_id' => $this->context->actor($request)->id,
            'subject_type' => 'task', 'subject_id' => $task->id, 'action' => 'task.relation_deleted',
            'before' => $snapshot, 'context' => ['link_id' => $snapshot['id'], ...$this->context->auditMetadata($request)],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json(null, 204);
    }

    private function assertTask(Scope $scope, Task $task): void
    {
        abort_unless($task->scope_id === $scope->id, 404);
    }

    private function present(EntityLink $link, Task $current, $tasks): array
    {
        $outbound = $link->source_id === $current->id;
        $otherId = $outbound ? $link->target_id : $link->source_id;
        $relation = $link->relation === 'blocks' && ! $outbound ? 'blocked_by' : $link->relation;

        return ['id' => $link->id, 'relation' => $relation, 'task' => $tasks->get($otherId), 'created_at' => $link->created_at];
    }
}
