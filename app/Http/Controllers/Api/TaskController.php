<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\MoveTaskRequest;
use App\Http\Requests\Api\StoreTaskRequest;
use App\Http\Requests\Api\UpdateTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\ActivityLog;
use App\Models\Comment;
use App\Models\EntityLink;
use App\Models\Project;
use App\Models\Scope;
use App\Models\Task;
use App\Models\User;
use App\Services\ContractorAccessService;
use App\Services\ContractorContext;
use App\Services\TaskCompletionService;
use App\Services\TaskKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class TaskController extends Controller
{
    public function __construct(
        private readonly ContractorAccessService $access,
        private readonly ContractorContext $context,
        private readonly TaskCompletionService $completion,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, Scope $scope): AnonymousResourceCollection
    {
        $query = $this->access->constrainTasks($scope->tasks()->getQuery(), $this->context->actor($request), $scope);

        return TaskResource::collection($query->with(['project:id,title,key,color', 'kpi:id,name,kind,points,minimum_completed_tasks', 'assignee:id,name,type', 'customer:id,name,type,position', 'delegatedAgent:id,name,type'])->orderBy('sort_order')->orderBy('created_at')->get());
    }

    public function search(Request $request, Scope $scope): AnonymousResourceCollection
    {
        $query = trim((string) $request->query('q'));
        abort_if(mb_strlen($query) < 2, 422, 'Search query must contain at least two characters.');

        $tasks = $this->access->constrainTasks($scope->tasks()->getQuery(), $this->context->actor($request), $scope);

        return TaskResource::collection($tasks
            ->with(['project:id,title,key,color', 'kpi:id,name,kind,points,minimum_completed_tasks', 'assignee:id,name,type', 'customer:id,name,type,position', 'delegatedAgent:id,name,type'])
            ->where(fn ($builder) => $builder->where('task_key', 'like', strtoupper($query).'%')->orWhere('title', 'like', '%'.$query.'%'))
            ->limit(20)->get());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreTaskRequest $request, Scope $scope, TaskKeyService $keys): TaskResource
    {
        $data = $request->validated();
        $this->normalizeAgentDelegation($data);
        $actor = $this->context->actor($request);
        if (empty($data['assignee_id']) && $actor->is_executor) {
            $data['assignee_id'] = $actor->id;
        }
        $this->assertReferencesBelongToScope($scope, $data, $actor);
        abort_if(! isset($data['project_id']) && ! $this->access->canAccessUnprojected($actor, $scope), 403, 'Unprojected tasks are outside the contractor access boundary.');
        $this->assertCanAssign($scope, $data, $actor);
        $this->assertAssignedUsersCanAccess($scope, $data, $data['project_id'] ?? null);
        if (! empty($data['parent_id'])) {
            $parent = $scope->tasks()->findOrFail($data['parent_id']);
            abort_if($parent->parent_id !== null, 422, 'Only one level of true subtasks is supported.');
        }

        abort_if(($data['status'] ?? null) === 'blocked', 422, 'Create the task first, then use the blocker endpoint.');
        $task = DB::transaction(function () use ($request, $scope, $keys, $data): Task {
            $project = isset($data['project_id']) ? Project::query()->findOrFail($data['project_id']) : null;
            $identity = $keys->reserve($scope, $project);

            return $scope->tasks()->create([...$data, ...$identity, 'created_by' => $this->context->actor($request)->id]);
        });
        ActivityLog::query()->create([
            'scope_id' => $scope->id,
            'actor_id' => $this->context->actor($request)->id,
            'subject_type' => $task->getMorphClass(),
            'subject_id' => $task->id,
            'action' => 'task.created',
            'after' => $task->only(['task_key', 'title', 'project_id', 'assignee_id', 'customer_id', 'status', 'priority', 'due_at']),
            'context' => $this->context->auditMetadata($request),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return new TaskResource($task->load(['project:id,title,key,color', 'kpi:id,name,kind,points,minimum_completed_tasks', 'assignee:id,name,type', 'customer:id,name,type,position', 'delegatedAgent:id,name,type']));
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Scope $scope, Task $task): TaskResource
    {
        abort_unless($this->access->canAccessTask($this->context->actor($request), $scope, $task), 404);

        return new TaskResource($task->load(['project:id,title,key,color', 'kpi:id,name,kind,points,minimum_completed_tasks', 'assignee:id,name,type', 'customer:id,name,type,position', 'delegatedAgent:id,name,type', 'checklistItems.assignee:id,name', 'checklistItems.completedBy:id,name', 'blockers.responsibleUser:id,name', 'blockers.blockedBy:id,name', 'blockers.resolvedBy:id,name', 'children:id,scope_id,project_id,parent_id,task_key,title,status,priority,due_at,assignee_id', 'plannerTails' => fn ($query) => $query->orderBy('planned_on')]));
    }

    public function update(UpdateTaskRequest $request, Scope $scope, Task $task): TaskResource
    {
        abort_unless($this->access->canAccessTask($this->context->actor($request), $scope, $task, 'task.update'), 404);
        $data = $request->validated();
        $this->normalizeAgentDelegation($data);
        $this->assertReferencesBelongToScope($scope, $data, $this->context->actor($request));
        abort_if(array_key_exists('project_id', $data) && $data['project_id'] === null && ! $this->access->canAccessUnprojected($this->context->actor($request), $scope), 403, 'Unprojected tasks are outside the contractor access boundary.');
        $fallbackProject = $task->project_id ? Project::query()->find($task->project_id) : null;
        $this->assertCanAssign($scope, $data, $this->context->actor($request), $fallbackProject);
        $targetProjectId = array_key_exists('project_id', $data) ? $data['project_id'] : $task->project_id;
        $assignmentData = [
            'assignee_id' => array_key_exists('assignee_id', $data) ? $data['assignee_id'] : $task->assignee_id,
            'is_agent_delegatable' => array_key_exists('is_agent_delegatable', $data) ? $data['is_agent_delegatable'] : $task->is_agent_delegatable,
            'delegated_agent_id' => array_key_exists('delegated_agent_id', $data) ? $data['delegated_agent_id'] : $task->delegated_agent_id,
        ];
        if (! $assignmentData['is_agent_delegatable']) {
            $assignmentData['delegated_agent_id'] = null;
            $data['delegated_agent_id'] = null;
        }
        $this->assertAssignedUsersCanAccess($scope, $assignmentData, $targetProjectId);

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

        $data = $this->completion->apply($task, $data, $this->context->actor($request));

        $before = $task->only(array_keys($data));
        $task->update($data);
        ActivityLog::query()->create([
            'scope_id' => $scope->id,
            'actor_id' => $this->context->actor($request)->id,
            'subject_type' => $task->getMorphClass(),
            'subject_id' => $task->id,
            'action' => array_key_exists('due_at', $data) ? 'task.planner_rescheduled' : 'task.updated',
            'before' => $before,
            'after' => $task->fresh()->only(array_keys($data)),
            'context' => $this->context->auditMetadata($request),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return new TaskResource($task->fresh()->load(['project:id,title,key,color', 'kpi:id,name,kind,points,minimum_completed_tasks', 'assignee:id,name,type', 'customer:id,name,type,position', 'delegatedAgent:id,name,type', 'checklistItems.assignee:id,name', 'checklistItems.completedBy:id,name', 'blockers.responsibleUser:id,name', 'blockers.blockedBy:id,name', 'blockers.resolvedBy:id,name', 'children:id,scope_id,project_id,parent_id,task_key,title,status,priority,due_at,assignee_id', 'plannerTails' => fn ($query) => $query->orderBy('planned_on')]));
    }

    public function move(MoveTaskRequest $request, Scope $scope, Task $task): TaskResource
    {
        abort_unless($task->scope_id === $scope->id, 404);
        abort_if($task->status === 'blocked' || $task->blockers()->whereNull('resolved_at')->exists(), 422, 'Resolve the active blocker before moving this task.');
        $targetStatus = $request->validated('status');
        $targetIndex = $request->integer('target_index');
        $before = ['status' => $task->status, 'sort_order' => $task->sort_order, 'assignee_id' => $task->assignee_id];

        DB::transaction(function () use ($request, $scope, $task, $targetStatus, $targetIndex, $before): void {
            $sourceStatus = $task->status;
            $taskQuery = $this->access->constrainTasks(Task::query()->where('scope_id', $scope->id), $this->context->actor($request), $scope);
            $groups = $taskQuery
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
                    $changes = $this->completion->apply($task, $changes, $this->context->actor($request));
                }
                $item->update($changes);
            }

            ActivityLog::query()->create([
                'scope_id' => $scope->id,
                'actor_id' => $this->context->actor($request)->id,
                'subject_type' => $task->getMorphClass(),
                'subject_id' => $task->id,
                'action' => 'task.moved',
                'before' => $before,
                'after' => ['status' => $targetStatus, 'sort_order' => ($target->search(fn (Task $item) => $item->id === $task->id) + 1) * 1000, 'assignee_id' => $task->fresh()->assignee_id],
                'context' => ['target_index' => $targetIndex, ...$this->context->auditMetadata($request)],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        });

        return new TaskResource($task->fresh()->load(['project:id,title,key,color', 'kpi:id,name,kind,points,minimum_completed_tasks', 'assignee:id,name,type', 'customer:id,name,type,position', 'delegatedAgent:id,name,type']));
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
            ], ['created_by' => $this->context->actor($request)->id, 'note' => 'Created when the subtask was extracted.']);
            ActivityLog::query()->create([
                'scope_id' => $scope->id, 'actor_id' => $this->context->actor($request)->id,
                'subject_type' => $lockedTask->getMorphClass(), 'subject_id' => $lockedTask->id,
                'action' => 'task.detached', 'before' => ['parent_id' => $formerParentId], 'after' => ['parent_id' => null],
                'context' => ['former_parent_id' => $formerParentId, ...$this->context->auditMetadata($request)], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
            ]);
        });

        return new TaskResource($task->fresh()->load(['project:id,title,key,color', 'kpi:id,name,kind,points,minimum_completed_tasks', 'assignee:id,name,type', 'customer:id,name,type,position', 'delegatedAgent:id,name,type']));
    }

    public function destroy(Request $request, Scope $scope, Task $task): Response
    {
        abort_unless($task->scope_id === $scope->id, 404);
        $actor = $this->context->actor($request);
        $project = $task->project_id ? Project::query()->find($task->project_id) : null;
        abort_if($project === null && ! $this->access->canAccessUnprojected($actor, $scope), 403);
        abort_unless($this->access->allows($actor, $scope, 'task.delete', $project), 403, 'The task.delete capability is required.');

        DB::transaction(function () use ($request, $scope, $task): void {
            $before = $task->only(['task_key', 'title', 'project_id', 'assignee_id', 'status']);
            $physical = $task->status === 'cancelled';
            if ($physical) {
                $this->forceDeleteTask($task);
            } else {
                $task->update(['status' => 'cancelled', 'completed_at' => null]);
            }
            ActivityLog::query()->create([
                'scope_id' => $scope->id,
                'actor_id' => $this->context->actor($request)->id,
                'subject_type' => $task->getMorphClass(),
                'subject_id' => $task->id,
                'action' => $physical ? 'task.deleted' : 'task.trashed',
                'before' => $before,
                'after' => $physical ? null : ['status' => 'cancelled'],
                'context' => ['physical' => $physical, ...$this->context->auditMetadata($request)],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        });

        return response()->noContent();
    }

    public function purgeTrash(Request $request, Scope $scope): JsonResponse
    {
        $actor = $this->context->actor($request);
        $tasks = $this->access->constrainTasks(
            Task::query()->where('scope_id', $scope->id)->where('status', 'cancelled'),
            $actor,
            $scope,
        )->with('project')->get()->filter(fn (Task $task): bool => $this->access->allows($actor, $scope, 'task.delete', $task->project));

        DB::transaction(function () use ($request, $scope, $tasks): void {
            foreach ($tasks as $task) {
                $before = $task->only(['task_key', 'title', 'project_id', 'assignee_id', 'status']);
                $this->forceDeleteTask($task);
                ActivityLog::query()->create([
                    'scope_id' => $scope->id,
                    'actor_id' => $this->context->actor($request)->id,
                    'subject_type' => $task->getMorphClass(),
                    'subject_id' => $task->id,
                    'action' => 'task.deleted',
                    'before' => $before,
                    'context' => ['physical' => true, 'trash_purge' => true, ...$this->context->auditMetadata($request)],
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            }
        });

        return response()->json(['data' => ['deleted_count' => $tasks->count()]]);
    }

    private function forceDeleteTask(Task $task): void
    {
        $task->children()->update(['parent_id' => null]);
        $task->plannerTails()->delete();
        Comment::query()->where('commentable_type', $task->getMorphClass())->where('commentable_id', $task->id)->delete();
        EntityLink::query()->where(function ($query) use ($task): void {
            $query->where(fn ($side) => $side->where('source_type', $task->getMorphClass())->where('source_id', $task->id))
                ->orWhere(fn ($side) => $side->where('target_type', $task->getMorphClass())->where('target_id', $task->id));
        })->delete();
        $task->forceDelete();
    }

    /** @param array<string, mixed> $data */
    private function assertReferencesBelongToScope(Scope $scope, array $data, User $actor): void
    {
        foreach (['project_id' => 'projects', 'parent_id' => 'tasks', 'kpi_id' => 'kpis'] as $key => $relation) {
            if (isset($data[$key])) {
                abort_unless($scope->{$relation}()->whereKey($data[$key])->exists(), 422, "Invalid {$key} for this scope.");
            }
        }

        if (! empty($data['customer_id'])) {
            abort_unless(User::query()->whereKey($data['customer_id'])->whereIn('type', ['real', 'virtual'])->where('status', 'active')->where('is_active', true)->where(function ($query) use ($scope): void {
                $query->whereKey($scope->owner_id)->orWhereHas('scopeMemberships', fn ($members) => $members->where('scope_id', $scope->id)->where('is_active', true));
            })->exists(), 422, 'The customer must be a real or virtual member of this scope.');
        }

        if (isset($data['project_id'])) {
            $project = Project::query()->findOrFail($data['project_id']);
            abort_unless($this->access->allows($actor, $scope, 'task.view', $project), 403, 'This project is outside the contractor access boundary.');
        }

        if (isset($data['parent_id'])) {
            $parent = Task::query()->with('project')->findOrFail($data['parent_id']);
            abort_unless($this->access->allows($actor, $scope, 'task.view', $parent->project), 403, 'The parent task is outside the contractor access boundary.');
        }
    }

    /** @param array<string, mixed> $data */
    private function assertCanAssign(Scope $scope, array $data, User $actor, ?Project $fallbackProject = null): void
    {
        if (! array_key_exists('assignee_id', $data) && ! array_key_exists('delegated_agent_id', $data) && ! array_key_exists('is_agent_delegatable', $data)) {
            return;
        }

        if (empty($data['assignee_id']) && empty($data['delegated_agent_id']) && empty($data['is_agent_delegatable'])) {
            return;
        }

        if (($data['assignee_id'] ?? null) === $actor->id && empty($data['delegated_agent_id']) && empty($data['is_agent_delegatable'])) {
            return;
        }

        $project = isset($data['project_id']) ? Project::query()->find($data['project_id']) : $fallbackProject;
        abort_unless($this->access->allows($actor, $scope, 'task.assign', $project), 403, 'The task.assign capability is required.');
    }

    /** @param array<string, mixed> $data */
    private function normalizeAgentDelegation(array &$data): void
    {
        if (! empty($data['delegated_agent_id'])) {
            $data['is_agent_delegatable'] = true;
        }

        if (array_key_exists('is_agent_delegatable', $data) && ! $data['is_agent_delegatable']) {
            $data['delegated_agent_id'] = null;
        }
    }

    /** @param array<string, mixed> $data */
    private function assertAssignedUsersCanAccess(Scope $scope, array $data, ?string $projectId): void
    {
        $project = $projectId ? Project::query()->findOrFail($projectId) : null;

        if (! empty($data['assignee_id'])) {
            $assignee = User::query()->whereKey($data['assignee_id'])->whereIn('type', ['real', 'virtual'])->where('status', 'active')->where('is_active', true)->first();
            abort_unless($assignee !== null && ($scope->owner_id === $assignee->id || $scope->members()->where('user_id', $assignee->id)->where('is_active', true)->exists()), 422, 'The assignee must be an active real or virtual member of this scope.');
            abort_if($project === null && ! $this->access->canAccessUnprojected($assignee, $scope), 422, 'The assignee cannot access unprojected tasks.');
            abort_unless($this->access->allows($assignee, $scope, 'task.view', $project), 422, 'The assignee cannot access this project.');
        }

        if (! empty($data['delegated_agent_id'])) {
            abort_unless($data['is_agent_delegatable'] ?? false, 422, 'Enable agent delegation before selecting an agent.');
            $agent = User::query()->whereKey($data['delegated_agent_id'])->where('type', 'agent')->where('status', 'active')->where('is_active', true)->first();
            abort_unless($agent !== null && $scope->members()->where('user_id', $agent->id)->where('is_active', true)->exists(), 422, 'The delegated agent must be an active member of this scope.');
            abort_if($project === null && ! $this->access->canAccessUnprojected($agent, $scope), 422, 'The delegated agent cannot access unprojected tasks.');
            abort_unless($this->access->allows($agent, $scope, 'task.view', $project), 422, 'The delegated agent cannot access this project.');
        }
    }
}
