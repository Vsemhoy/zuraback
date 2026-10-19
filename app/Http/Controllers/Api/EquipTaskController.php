<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\EntityLink;
use App\Models\Scope;
use App\Models\Task;
use App\Models\Thing;
use App\Services\ContractorAccessService;
use App\Services\ContractorContext;
use App\Services\EquipAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class EquipTaskController extends Controller
{
    public function __construct(private readonly EquipAccessService $equip,
        private readonly ContractorAccessService $access, private readonly ContractorContext $context) {}

    public function index(Request $request, Scope $scope, Thing $thing): JsonResponse
    {
        $this->authorizeThing($request, $scope, $thing);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'page' => ['sometimes', 'integer', 'min:1'], 'candidates' => ['sometimes', 'boolean']]);
        $actor = $this->context->actor($request);
        abort_unless($this->access->allows($actor, $scope, 'task.view'), 403);
        $links = $this->links($scope, $thing);
        $query = $this->access->constrainTasks(Task::query()->where('scope_id', $scope->id), $actor, $scope);
        $linkedIds = (clone $links)->select('target_id');
        $request->boolean('candidates') ? $query->whereNotIn('id', $linkedIds) : $query->whereIn('id', $linkedIds);
        if (! empty($data['q'])) {
            $query->where(fn (Builder $q) => $q->where('title', 'like', '%'.$data['q'].'%')->orWhere('task_key', 'like', '%'.$data['q'].'%'));
        }
        $page = $query->with('assignee:id,name')->latest('updated_at')->orderByDesc('id')->simplePaginate(30);

        return response()->json(['data' => $page->getCollection()->map(fn (Task $task): array => [
            'id' => $task->id, 'title' => $task->title, 'task_key' => $task->task_key, 'status' => $task->status,
            'assignee' => $task->assignee?->only(['id', 'name']), 'completed_at' => $task->completed_at,
        ]), 'meta' => ['page' => $page->currentPage(), 'has_more' => $page->hasMorePages()]]);
    }

    public function store(Request $request, Scope $scope, Thing $thing): Response
    {
        $data = $request->validate(['task_id' => ['required', 'ulid']]);
        DB::transaction(function () use ($request, $scope, $thing, $data): void {
            Scope::whereKey($scope->id)->lockForUpdate()->firstOrFail();
            $current = Thing::whereKey($thing->id)->lockForUpdate()->firstOrFail();
            $this->authorizeThing($request, $scope, $current, true);
            $task = Task::findOrFail($data['task_id']);
            abort_unless($this->access->canAccessTask($this->context->actor($request), $scope, $task), 404);
            $link = $this->links($scope, $current)->firstOrCreate(['target_id' => $task->id], [
                'scope_id' => $scope->id, 'source_type' => 'thing', 'source_id' => $current->id,
                'target_type' => 'task', 'relation' => 'related', 'created_by' => $this->context->actor($request)->id,
            ]);
            if ($link->wasRecentlyCreated) {
                $this->audit($request, $scope, $current, $task, 'task_linked');
            }
        });

        return response()->noContent();
    }

    public function destroy(Request $request, Scope $scope, Thing $thing, Task $task): Response
    {
        DB::transaction(function () use ($request, $scope, $thing, $task): void {
            Scope::whereKey($scope->id)->lockForUpdate()->firstOrFail();
            $current = Thing::whereKey($thing->id)->lockForUpdate()->firstOrFail();
            $this->authorizeThing($request, $scope, $current, true);
            abort_unless($this->access->canAccessTask($this->context->actor($request), $scope, $task), 404);
            $link = $this->links($scope, $current)->where('target_id', $task->id)->firstOrFail();
            $link->delete();
            $this->audit($request, $scope, $current, $task, 'task_unlinked');
        });

        return response()->noContent();
    }

    private function authorizeThing(Request $request, Scope $scope, Thing $thing, bool $write = false): void
    {
        abort_unless($this->equip->thing($this->context->actor($request), $scope, $thing), 404);
        if ($write) {
            abort_unless($this->equip->thing($this->context->actor($request), $scope, $thing, 'equip.update'), 403);
        }
    }

    private function links(Scope $scope, Thing $thing): Builder
    {
        return EntityLink::where('scope_id', $scope->id)->where('source_type', 'thing')
            ->where('source_id', $thing->id)->where('target_type', 'task');
    }

    private function audit(Request $request, Scope $scope, Thing $thing, Task $task, string $action): void
    {
        ActivityLog::create(['scope_id' => $scope->id, 'actor_id' => $this->context->actor($request)->id,
            'subject_type' => 'thing', 'subject_id' => $thing->id, 'action' => 'equip.'.$action,
            'after' => ['task_id' => $task->id], 'context' => $this->context->auditMetadata($request)]);
    }
}
