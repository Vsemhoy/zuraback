<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ResolveTaskBlockerRequest;
use App\Http\Requests\Api\StoreTaskBlockerRequest;
use App\Http\Resources\TaskBlockerResource;
use App\Models\ActivityLog;
use App\Models\Scope;
use App\Models\Task;
use App\Models\TaskBlocker;
use App\Services\ContractorContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class TaskBlockerController extends Controller
{
    public function __construct(private readonly ContractorContext $context) {}

    public function index(Scope $scope, Task $task): AnonymousResourceCollection
    {
        $this->assertTask($scope, $task);

        return TaskBlockerResource::collection($task->blockers()->with(['responsibleUser:id,name', 'blockedBy:id,name', 'resolvedBy:id,name'])->latest('blocked_at')->get());
    }

    public function store(StoreTaskBlockerRequest $request, Scope $scope, Task $task): TaskBlockerResource
    {
        $this->assertTask($scope, $task);
        $data = $request->validated();
        if (isset($data['responsible_user_id'])) {
            abort_unless($scope->members()->where('user_id', $data['responsible_user_id'])->exists(), 422, 'The responsible user must belong to this scope.');
        }

        $blocker = DB::transaction(function () use ($request, $task, $data): TaskBlocker {
            $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->id);
            abort_if($lockedTask->blockers()->whereNull('resolved_at')->exists(), 422, 'The task already has an active blocker.');
            abort_if(in_array($lockedTask->status, ['done', 'cancelled'], true), 422, 'A completed or cancelled task cannot be blocked.');

            $blocker = $lockedTask->blockers()->create([
                ...$data,
                'previous_status' => $lockedTask->status,
                'blocked_by' => $this->context->actor($request)->id,
                'blocked_at' => now(),
            ]);
            $lockedTask->update(['status' => 'blocked']);
            $this->log($request, $lockedTask, 'task.blocked', null, $blocker->only(['id', 'reason', 'resolution_required', 'responsible_user_id', 'responsible_text', 'blocked_at']));

            return $blocker;
        });

        return new TaskBlockerResource($blocker->load(['responsibleUser:id,name', 'blockedBy:id,name', 'resolvedBy:id,name']));
    }

    public function resolve(ResolveTaskBlockerRequest $request, Scope $scope, Task $task, TaskBlocker $blocker): TaskBlockerResource
    {
        $this->assertBlocker($scope, $task, $blocker);

        DB::transaction(function () use ($request, $task, $blocker): void {
            $lockedBlocker = TaskBlocker::query()->lockForUpdate()->findOrFail($blocker->id);
            abort_if($lockedBlocker->resolved_at !== null, 422, 'The blocker has already been resolved.');
            $before = $lockedBlocker->only(['id', 'resolved_at']);

            $lockedBlocker->update([
                'resolved_by' => $this->context->actor($request)->id,
                'resolved_at' => now(),
                'resolution_note' => $request->validated('resolution_note'),
            ]);
            $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->id);
            if ($lockedTask->status === 'blocked') {
                $lockedTask->update(['status' => $lockedBlocker->previous_status]);
            }
            $this->log($request, $lockedTask, 'task.unblocked', $before, $lockedBlocker->fresh()->only(['id', 'resolved_at', 'resolution_note']));
        });

        return new TaskBlockerResource($blocker->fresh()->load(['responsibleUser:id,name', 'blockedBy:id,name', 'resolvedBy:id,name']));
    }

    private function assertTask(Scope $scope, Task $task): void
    {
        abort_unless($task->scope_id === $scope->id, 404);
    }

    private function assertBlocker(Scope $scope, Task $task, TaskBlocker $blocker): void
    {
        $this->assertTask($scope, $task);
        abort_unless($blocker->task_id === $task->id, 404);
    }

    private function log(Request $request, Task $task, string $action, ?array $before, array $after): void
    {
        ActivityLog::query()->create([
            'scope_id' => $task->scope_id,
            'actor_id' => $this->context->actor($request)->id,
            'subject_type' => $task->getMorphClass(),
            'subject_id' => $task->id,
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'context' => ['blocker_id' => $after['id'], ...$this->context->auditMetadata($request)],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
