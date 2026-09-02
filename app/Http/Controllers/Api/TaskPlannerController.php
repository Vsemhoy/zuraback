<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\ActivityLog;
use App\Models\EntityLink;
use App\Models\Project;
use App\Models\Scope;
use App\Models\Task;
use App\Models\TaskPlannerTail;
use App\Models\User;
use App\Services\ContractorAccessService;
use App\Services\ContractorContext;
use App\Services\TaskCompletionService;
use App\Services\TaskKeyService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TaskPlannerController extends Controller
{
    private const STATUSES = ['scheduled', 'todo', 'in_progress', 'blocked', 'review', 'done', 'cancelled'];

    public function __construct(
        private readonly ContractorAccessService $access,
        private readonly ContractorContext $context,
        private readonly TaskCompletionService $completion,
    ) {}

    public function index(Request $request, Scope $scope): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'project_ids' => ['sometimes', 'array'], 'project_ids.*' => ['ulid'],
            'assignee_ids' => ['sometimes', 'array'], 'assignee_ids.*' => ['ulid'],
            'statuses' => ['sometimes', 'array'], 'statuses.*' => [Rule::in(self::STATUSES)],
        ]);
        $from = CarbonImmutable::parse($data['from'])->startOfDay();
        $to = CarbonImmutable::parse($data['to'])->endOfDay();
        abort_if($from->diffInDays($to) > 120, 422, 'Calendar range cannot exceed 121 days.');

        $query = $this->taskQuery($request, $scope, $data);
        $tasks = (clone $query)->whereBetween('due_at', [$from, $to])
            ->with(['project:id,title,key,color', 'assignee:id,name,type', 'delegatedAgent:id,name,type'])
            ->orderBy('due_at')->orderBy('sort_order')->get();
        $unscheduled = (clone $query)->whereNull('due_at')
            ->whereNotIn('status', ['done', 'cancelled'])
            ->with(['project:id,title,key,color', 'assignee:id,name,type', 'delegatedAgent:id,name,type'])
            ->orderByDesc('updated_at')->limit(200)->get();
        $accessibleIds = (clone $query)->select('tasks.id');
        $tails = TaskPlannerTail::query()->where('scope_id', $scope->id)
            ->whereBetween('planned_on', [$data['from'], $data['to']])
            ->whereIn('task_id', $accessibleIds)
            ->with(['task.project:id,title,key,color', 'task.assignee:id,name,type'])
            ->orderBy('planned_on')->get();

        return response()->json([
            'data' => [
                'tasks' => TaskResource::collection($tasks)->resolve($request),
                'unscheduled' => TaskResource::collection($unscheduled)->resolve($request),
                'tails' => $tails->map(fn (TaskPlannerTail $tail): array => [
                    'id' => $tail->id,
                    'planned_on' => $tail->planned_on->format('Y-m-d'),
                    'task' => (new TaskResource($tail->task))->resolve($request),
                ])->values(),
            ],
        ]);
    }

    public function storeTail(Request $request, Scope $scope): JsonResponse
    {
        $data = $request->validate(['task_id' => ['required', 'ulid'], 'planned_on' => ['required', 'date_format:Y-m-d']]);
        $task = $this->accessibleTask($request, $scope, $data['task_id']);
        $origin = $task->due_at?->startOfDay() ?? today();
        abort_unless(CarbonImmutable::parse($data['planned_on'])->startOfDay()->greaterThan($origin), 422, 'A task tail must point to a later planning day.');
        $tail = TaskPlannerTail::query()->firstOrCreate([
            'scope_id' => $scope->id, 'task_id' => $task->id, 'planned_on' => $data['planned_on'],
        ], ['created_by' => $this->context->actor($request)->id]);
        if ($tail->wasRecentlyCreated) {
            $this->log($request, $scope, $task, 'task.planner_tail_created', null, ['tail_id' => $tail->id, 'planned_on' => $tail->planned_on->format('Y-m-d')]);
        }

        return response()->json(['data' => ['id' => $tail->id, 'planned_on' => $tail->planned_on->format('Y-m-d'), 'task' => (new TaskResource($task->load(['project:id,title,key,color', 'assignee:id,name,type'])))->resolve($request)]], $tail->wasRecentlyCreated ? 201 : 200);
    }

    public function moveTail(Request $request, Scope $scope, TaskPlannerTail $tail): JsonResponse
    {
        abort_unless($tail->scope_id === $scope->id, 404);
        $task = $this->accessibleTask($request, $scope, $tail->task_id);
        $data = $request->validate(['planned_on' => ['required', 'date_format:Y-m-d']]);
        $before = ['tail_id' => $tail->id, 'planned_on' => $tail->planned_on->format('Y-m-d')];
        $origin = $tail->task()->value('due_at');
        abort_unless(CarbonImmutable::parse($data['planned_on'])->startOfDay()->greaterThan($origin ? CarbonImmutable::parse($origin)->startOfDay() : today()), 422, 'A task tail must point to a later planning day.');
        $duplicate = TaskPlannerTail::query()->where('task_id', $tail->task_id)->whereDate('planned_on', $data['planned_on'])->whereKeyNot($tail->id)->first();
        if ($duplicate) {
            $tail->delete();
            $tail = $duplicate;
        } else {
            $tail->update($data);
        }
        $this->log($request, $scope, $task, 'task.planner_tail_moved', $before, ['tail_id' => $tail->id, 'planned_on' => $tail->planned_on->format('Y-m-d')]);

        return response()->json(['data' => ['id' => $tail->id, 'planned_on' => $tail->planned_on->format('Y-m-d')]]);
    }

    public function destroyTail(Request $request, Scope $scope, TaskPlannerTail $tail): JsonResponse
    {
        abort_unless($tail->scope_id === $scope->id, 404);
        $task = $this->accessibleTask($request, $scope, $tail->task_id);
        $before = ['tail_id' => $tail->id, 'planned_on' => $tail->planned_on->format('Y-m-d')];
        $tail->delete();
        $this->log($request, $scope, $task, 'task.planner_tail_deleted', $before, null);

        return response()->json(null, 204);
    }

    public function copyTask(Request $request, Scope $scope, Task $task, TaskKeyService $keys): JsonResponse
    {
        abort_unless($task->scope_id === $scope->id, 404);
        $this->accessibleTask($request, $scope, $task->id);
        $data = $request->validate(['planned_on' => ['required', 'date_format:Y-m-d']]);
        $copy = DB::transaction(function () use ($request, $scope, $task, $keys, $data): Task {
            $task->load('checklistItems');
            $project = $task->project_id ? Project::query()->findOrFail($task->project_id) : null;
            $identity = $keys->reserve($scope, $project);
            $copy = $scope->tasks()->create([
                ...$task->only(['project_id', 'assignee_id', 'is_agent_delegatable', 'delegated_agent_id', 'kpi_id', 'title', 'description', 'result', 'agent_notes', 'status', 'priority']),
                ...$identity,
                'due_at' => $data['planned_on'].' 12:00:00',
                'completed_at' => null,
                'approved_at' => null,
                'tracked_seconds' => 0,
                'created_by' => $this->context->actor($request)->id,
            ]);
            foreach ($task->checklistItems as $item) {
                $copy->checklistItems()->create([
                    'created_by' => $this->context->actor($request)->id,
                    'assignee_id' => $item->assignee_id,
                    'title' => $item->title,
                    'due_at' => $item->due_at,
                    'sort_order' => $item->sort_order,
                    'meta' => $item->meta,
                ]);
            }

            return $copy;
        });
        $this->log($request, $scope, $task, 'task.planner_copied', ['task_id' => $task->id, 'due_at' => $task->due_at], ['task_id' => $copy->id, 'task_key' => $copy->task_key, 'due_at' => $copy->due_at]);
        $this->log($request, $scope, $copy, 'task.planner_created_from_copy', ['source_task_id' => $task->id, 'source_task_key' => $task->task_key], ['due_at' => $copy->due_at]);

        return response()->json(['data' => (new TaskResource($copy->load(['project:id,title,key,color', 'assignee:id,name,type'])))->resolve($request)], 201);
    }

    public function bulk(Request $request, Scope $scope): JsonResponse
    {
        $data = $request->validate([
            'task_ids' => ['required', 'array', 'min:1', 'max:200'], 'task_ids.*' => ['required', 'ulid', 'distinct'],
            'project_id' => ['sometimes', 'nullable', 'ulid'],
            'assignee_id' => ['sometimes', 'nullable', 'ulid'],
            'status' => ['sometimes', Rule::in(array_diff(self::STATUSES, ['blocked']))],
            'priority' => ['sometimes', 'integer', 'between:1,5'],
            'description' => ['sometimes', 'nullable', 'string'],
            'checklist_item' => ['sometimes', 'nullable', 'string', 'max:255'],
            'relation_task_key' => ['sometimes', 'nullable', 'string', 'max:32'],
            'relation' => ['sometimes', Rule::in(['related', 'duplicate', 'blocks', 'blocked_by'])],
        ]);
        $actor = $this->context->actor($request);
        $tasks = $this->access->constrainTasks(Task::query()->where('scope_id', $scope->id)->whereIn('id', $data['task_ids']), $actor, $scope)->with('project')->get();
        abort_unless($tasks->count() === count($data['task_ids']), 403, 'One or more tasks are outside the contractor access boundary.');
        $project = null;
        if (array_key_exists('project_id', $data) && $data['project_id'] !== null) {
            $project = $scope->projects()->findOrFail($data['project_id']);
            abort_unless($this->access->allows($actor, $scope, 'task.view', $project), 403);
        }
        if (array_key_exists('project_id', $data) && $data['project_id'] === null) {
            abort_unless($this->access->canAccessUnprojected($actor, $scope), 403);
        }
        $assignee = null;
        if (! empty($data['assignee_id'])) {
            $assignee = User::query()->whereKey($data['assignee_id'])->whereIn('type', ['real', 'virtual'])->where('status', 'active')->where('is_active', true)->firstOrFail();
            abort_unless($scope->owner_id === $assignee->id || $scope->members()->where('user_id', $assignee->id)->where('is_active', true)->exists(), 422, 'The assignee must be an active scope member.');
        }
        $relationTarget = null;
        if (! empty($data['relation_task_key'])) {
            $relationTarget = $scope->tasks()->where('task_key', strtoupper($data['relation_task_key']))->firstOrFail();
            $this->accessibleTask($request, $scope, $relationTarget->id);
        }

        DB::transaction(function () use ($request, $scope, $tasks, $data, $actor, $assignee, $relationTarget): void {
            foreach ($tasks as $task) {
                $changes = collect($data)->only(['project_id', 'assignee_id', 'status', 'priority', 'description'])->all();
                $before = $task->only(array_keys($changes));
                if ($assignee) {
                    $targetProject = array_key_exists('project_id', $changes) ? ($changes['project_id'] ? Project::query()->find($changes['project_id']) : null) : $task->project;
                    abort_if($targetProject === null && ! $this->access->canAccessUnprojected($assignee, $scope), 422, 'The assignee cannot access an unprojected task.');
                    abort_unless($this->access->allows($assignee, $scope, 'task.view', $targetProject), 422, 'The assignee cannot access the target project.');
                }
                $changes = $this->completion->apply($task, $changes, $actor);
                $task->update($changes);
                if (! empty($data['checklist_item'])) {
                    $task->checklistItems()->create(['created_by' => $actor->id, 'title' => $data['checklist_item'], 'sort_order' => ((int) $task->checklistItems()->max('sort_order')) + 1000]);
                }
                if ($relationTarget && $relationTarget->id !== $task->id) {
                    $reverse = ($data['relation'] ?? 'related') === 'blocked_by';
                    $relation = $reverse ? 'blocks' : ($data['relation'] ?? 'related');
                    $source = $reverse ? $relationTarget : $task;
                    $target = $reverse ? $task : $relationTarget;
                    if (in_array($relation, ['related', 'duplicate'], true) && strcmp($source->id, $target->id) > 0) {
                        [$source, $target] = [$target, $source];
                    }
                    EntityLink::query()->firstOrCreate([
                        'scope_id' => $scope->id, 'source_type' => 'task', 'source_id' => $source->id,
                        'target_type' => 'task', 'target_id' => $target->id, 'relation' => $relation,
                    ], ['created_by' => $actor->id]);
                }
                $this->log($request, $scope, $task, 'task.planner_bulk_updated', $before, [
                    ...$task->fresh()->only(array_keys($changes)),
                    'checklist_item' => $data['checklist_item'] ?? null,
                    'relation_task_key' => $data['relation_task_key'] ?? null,
                    'relation' => $data['relation'] ?? null,
                ]);
            }
        });

        return response()->json(['data' => ['updated' => $tasks->count()]]);
    }

    /** @param array<string, mixed> $filters */
    private function taskQuery(Request $request, Scope $scope, array $filters)
    {
        $query = $this->access->constrainTasks(Task::query()->where('scope_id', $scope->id), $this->context->actor($request), $scope);
        if (array_key_exists('project_ids', $filters)) {
            $filters['project_ids'] === [] ? $query->whereNull('project_id') : $query->whereIn('project_id', $filters['project_ids']);
        }
        if (! empty($filters['assignee_ids'])) {
            $query->whereIn('assignee_id', $filters['assignee_ids']);
        }
        if (! empty($filters['statuses'])) {
            $query->whereIn('status', $filters['statuses']);
        }

        return $query;
    }

    private function accessibleTask(Request $request, Scope $scope, string $taskId): Task
    {
        return $this->access->constrainTasks(Task::query()->where('scope_id', $scope->id), $this->context->actor($request), $scope)->findOrFail($taskId);
    }

    /** @param array<string, mixed>|null $before @param array<string, mixed>|null $after */
    private function log(Request $request, Scope $scope, Task $task, string $action, ?array $before, ?array $after): void
    {
        ActivityLog::query()->create([
            'scope_id' => $scope->id,
            'actor_id' => $this->context->actor($request)->id,
            'subject_type' => $task->getMorphClass(),
            'subject_id' => $task->id,
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'context' => $this->context->auditMetadata($request),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
