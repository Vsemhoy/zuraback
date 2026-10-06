<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\MonthlyReportRequest;
use App\Http\Requests\Api\MonthlyTaskPlanRequest;
use App\Models\ActivityLog;
use App\Models\MonthlyReport;
use App\Models\MonthlyTaskPlan;
use App\Models\Scope;
use App\Models\User;
use App\Services\ContractorAccessService;
use App\Services\ContractorContext;
use App\Services\MonthlyReportService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MonthlyReportController extends Controller
{
    public function __construct(private readonly MonthlyReportService $reports, private readonly ContractorAccessService $access, private readonly ContractorContext $context) {}

    public function show(MonthlyReportRequest $request, Scope $scope): JsonResource
    {
        return new JsonResource($this->reports->build($this->context->actor($request), $scope, $request->validated()));
    }

    public function store(MonthlyReportRequest $request, Scope $scope): JsonResource
    {
        $report = $this->reports->archive($this->context->actor($request), $scope, $request->validated());

        return new JsonResource($report);
    }

    public function index(MonthlyReportRequest $request, Scope $scope): AnonymousResourceCollection
    {
        $actor = $this->context->actor($request);
        $rows = MonthlyReport::query()->where('scope_id', $scope->id)->where('created_by', $actor->id)
            ->where('month', $request->validated('month'))->latest()->orderByDesc('id')->paginate(20);
        $rows->setCollection($rows->getCollection()->filter(fn (MonthlyReport $report): bool => $this->reports->canDownload($actor, $scope, $report))->values());

        return JsonResource::collection($rows);
    }

    public function download(Request $request, Scope $scope, MonthlyReport $monthlyReport): StreamedResponse
    {
        abort_unless($this->reports->canDownload($this->context->actor($request), $scope, $monthlyReport), 404);
        $disk = Storage::disk('reports');
        abort_unless($disk->exists($monthlyReport->file_path), 404, 'Файл отчёта отсутствует.');

        return $disk->download($monthlyReport->file_path, 'Zuratax-'.$monthlyReport->month.'-'.$monthlyReport->id.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function candidates(Request $request, Scope $scope): AnonymousResourceCollection
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:150']]);
        $query = $this->reports->visibleTasks($this->context->actor($request), $scope)
            ->whereNotIn('status', ['done', 'cancelled'])
            ->when($data['q'] ?? null, fn ($query, $term) => $query->where(fn ($query) => $query->where('title', 'like', '%'.$term.'%')->orWhere('task_key', 'like', '%'.$term.'%')))
            ->with(['project:id,title,key', 'assignee:id,name'])->orderBy('sort_order')->orderBy('id');

        return JsonResource::collection($query->paginate(30, ['id', 'project_id', 'task_key', 'title', 'assignee_id', 'status', 'due_at']));
    }

    public function savePlan(MonthlyTaskPlanRequest $request, Scope $scope): JsonResource
    {
        $data = $request->validated();
        abort_unless(substr($data['planned_on'], 0, 7) === $data['month'], 422, 'Дата задачи должна быть в пределах месяца плана.');
        $actor = $this->context->actor($request);
        $task = $this->reports->visibleTasks($actor, $scope)->with('project')->findOrFail($data['task_id']);
        abort_unless($this->access->canAccessTask($actor, $scope, $task, 'task.update'), 403);
        abort_if(in_array($task->status, ['done', 'cancelled'], true), 422, 'В план добавляются только незавершённые задачи.');
        $assigneeId = $data['assignee_id'] ?? null;
        if ($assigneeId) {
            $person = User::query()->whereKey($assigneeId)->where('is_executor', true)->whereIn('type', ['real', 'virtual'])->first();
            $member = $scope->owner_id === $assigneeId || $scope->members()->where('user_id', $assigneeId)->where('is_active', true)->exists();
            abort_unless($person && $member && $this->access->canAccessTask($person, $scope, $task), 422, 'Исполнитель недоступен для этой задачи.');
        }
        $plan = DB::transaction(function () use ($scope, $task, $data, $assigneeId, $actor, $request): MonthlyTaskPlan {
            $task = $task->newQuery()->whereKey($task->id)->lockForUpdate()->firstOrFail();
            if ($task->due_at?->toDateString() !== $data['planned_on']) {
                $previousDate = $task->due_at;
                $task->update(['due_at' => $data['planned_on'].' 12:00:00']);
                ActivityLog::query()->create(['scope_id' => $scope->id, 'actor_id' => $actor->id, 'subject_type' => 'task', 'subject_id' => $task->id,
                    'action' => 'task.planner_rescheduled', 'before' => ['due_at' => $previousDate], 'after' => ['due_at' => $task->due_at], 'context' => $this->context->auditMetadata($request)]);
            }
            $plan = MonthlyTaskPlan::query()->firstOrNew(['task_id' => $task->id, 'month' => $data['month']]);
            $before = $plan->exists ? $plan->toArray() : null;
            $plan->fill(['scope_id' => $scope->id, 'created_by' => $plan->created_by ?? $actor->id, 'assignee_id' => $assigneeId, 'expected_result' => $data['expected_result'] ?? null])->save();
            ActivityLog::query()->create([
                'scope_id' => $scope->id, 'actor_id' => $actor->id, 'subject_type' => 'task', 'subject_id' => $task->id,
                'action' => 'task.monthly_plan_saved', 'before' => $before, 'after' => $plan->toArray(), 'context' => $this->context->auditMetadata($request),
            ]);

            return $plan;
        });

        return new JsonResource($plan);
    }

    public function deletePlan(Request $request, Scope $scope, MonthlyTaskPlan $monthlyTaskPlan): Response
    {
        $actor = $this->context->actor($request);
        abort_unless($monthlyTaskPlan->scope_id === $scope->id, 404);
        $task = $this->reports->visibleTasks($actor, $scope)->with('project')->findOrFail($monthlyTaskPlan->task_id);
        abort_unless($this->access->canAccessTask($actor, $scope, $task, 'task.update'), 403);
        DB::transaction(function () use ($monthlyTaskPlan, $scope, $actor, $task, $request): void {
            ActivityLog::query()->create([
                'scope_id' => $scope->id, 'actor_id' => $actor->id, 'subject_type' => 'task', 'subject_id' => $task->id,
                'action' => 'task.monthly_plan_removed', 'before' => $monthlyTaskPlan->toArray(), 'context' => $this->context->auditMetadata($request),
            ]);
            $monthlyTaskPlan->delete();
        });

        return response()->noContent();
    }
}
