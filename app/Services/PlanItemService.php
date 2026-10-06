<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\PlanItem;
use App\Models\Project;
use App\Models\Scope;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlanItemService
{
    public function __construct(private readonly ContractorAccessService $access) {}

    public function projectIds(User $actor, Scope $scope, bool $report = false): array
    {
        return $this->access->constrainProjects($scope->projects()->getQuery(), $actor, $scope)
            ->when($report, fn ($query) => $query->where('include_in_reports', true))->get()
            ->filter(fn (Project $project): bool => $this->access->canAccessProject($actor, $scope, $project)
                && $this->access->canAccessProject($actor, $scope, $project, 'report.view'))->modelKeys();
    }

    public function visible(User $actor, Scope $scope, bool $report = false): Builder
    {
        $ids = $this->projectIds($actor, $scope, $report);

        return PlanItem::query()->where('scope_id', $scope->id)->where(function (Builder $query) use ($ids, $actor, $scope): void {
            $query->whereIn('project_id', $ids);
            if ($this->access->canAccessUnprojected($actor, $scope)) {
                $query->orWhereNull('project_id');
            }
        });
    }

    public function tasks(User $actor, Scope $scope, bool $report = false): Builder
    {
        $ids = $this->projectIds($actor, $scope, $report);

        return Task::query()->where('scope_id', $scope->id)->where(function (Builder $query) use ($ids, $actor, $scope): void {
            $query->whereIn('project_id', $ids);
            if ($this->access->canAccessUnprojected($actor, $scope)) {
                $query->orWhereNull('project_id');
            }
        });
    }

    public function details(Builder $query, User $actor, Scope $scope, bool $report = false): Builder
    {
        $visibleTasks = $this->tasks($actor, $scope, $report)->select('tasks.id');

        return $query->with(['project:id,title,key,include_in_reports', 'assignee:id,name', 'completer:id,name',
            'tasks' => fn ($tasks) => $tasks->whereIn('tasks.id', $visibleTasks)->select(['tasks.id', 'scope_id', 'project_id', 'task_key', 'title', 'status', 'due_at'])
                ->with($this->taskPlanRelations($actor, $scope, $report))]);
    }

    public function taskPlanRelations(User $actor, Scope $scope, bool $report = false): array
    {
        $visiblePlans = $this->visible($actor, $scope, $report)->select('plan_items.id');

        return ['planItems' => fn ($plans) => $plans->whereIn('plan_items.id', $visiblePlans)
            ->select(['plan_items.id', 'scope_id', 'project_id', 'title', 'month'])->orderBy('month')->orderBy('plan_items.id')];
    }

    public function taskRow(Task $task): array
    {
        return ['id' => $task->id, 'task_key' => $task->task_key, 'title' => $task->title, 'status' => $task->status, 'due_at' => $task->due_at,
            'linked_plans' => $task->planItems
                ->filter(fn (PlanItem $plan): bool => $plan->scope_id === $task->scope_id && $plan->project_id === $task->project_id)
                ->map(fn (PlanItem $plan): array => $plan->only(['id', 'title', 'month']))->values()->all()];
    }

    public function row(PlanItem $item): array
    {
        // A task moved to another project no longer contributes to the original project's plan.
        $tasks = $item->tasks->filter(fn (Task $task): bool => $task->scope_id === $item->scope_id && $task->project_id === $item->project_id)->values();

        return [...$item->attributesToArray(),
            'project' => $item->project, 'assignee' => $item->assignee, 'completer' => $item->completer,
            'tasks' => $tasks->map(fn (Task $task): array => $this->taskRow($task))->all(),
            'tasks_count' => $tasks->count(), 'completed_tasks_count' => $tasks->where('status', 'done')->count()];
    }

    public function year(User $actor, Scope $scope, int $year, ?string $personId = null): array
    {
        $query = $this->visible($actor, $scope, true)->whereBetween('month', [$year.'-01', $year.'-12'])
            ->when($personId, fn ($q) => $q->where('assignee_id', $personId))->orderBy('month')->orderByDesc('priority')->orderBy('id');
        $items = $this->details($query, $actor, $scope, true)->limit(5001)->get();
        abort_if($items->count() > 5000, 422, 'Слишком большой годовой план. Выберите сотрудника.');

        return $items->map(fn (PlanItem $item): array => $this->row($item))->all();
    }

    public function authorizeWrite(User $actor, Scope $scope, ?string $projectId): void
    {
        abort_unless($this->access->allows($actor, $scope, 'report.write'), 403);
        if ($projectId) {
            $project = Project::query()->find($projectId);
            abort_unless($project && $this->access->canAccessProject($actor, $scope, $project, 'task.update')
                && $this->access->canAccessProject($actor, $scope, $project, 'report.write'), 404);
        } else {
            abort_unless($this->access->canAccessUnprojected($actor, $scope) && $this->access->allows($actor, $scope, 'task.update'), 403);
        }
    }

    public function save(User $actor, Scope $scope, PlanItem $item, array $data, Request $request, array $audit): PlanItem
    {
        return DB::transaction(function () use ($actor, $scope, $item, $data, $audit): PlanItem {
            if ($item->exists) {
                $item = $this->visible($actor, $scope)->whereKey($item->id)->lockForUpdate()->firstOrFail();
                $this->authorizeWrite($actor, $scope, $item->project_id);
            }
            $before = $item->exists ? $item->toArray() : null;
            $projectId = array_key_exists('project_id', $data) ? $data['project_id'] : $item->project_id;
            $this->authorizeWrite($actor, $scope, $projectId);
            $starts = $data['starts_on'] ?? (array_key_exists('starts_on', $data) ? null : $item->starts_on?->toDateString());
            $ends = $data['ends_on'] ?? (array_key_exists('ends_on', $data) ? null : $item->ends_on?->toDateString());
            if ($starts && $ends && $ends < $starts) {
                throw ValidationException::withMessages(['ends_on' => 'Конец диапазона не может быть раньше начала.']);
            }
            $assigneeId = array_key_exists('assignee_id', $data) ? $data['assignee_id'] : $item->assignee_id;
            $keepsDeletedAssignee = $item->exists && $assigneeId && $assigneeId === $item->assignee_id
                && User::onlyTrashed()->whereKey($assigneeId)->exists();
            if ($assigneeId && ! $keepsDeletedAssignee) {
                $person = User::query()->whereKey($assigneeId)->where('is_executor', true)->whereIn('type', ['real', 'virtual'])->first();
                $member = $scope->owner_id === $assigneeId || $scope->members()->where('user_id', $assigneeId)->where('is_active', true)->exists();
                $project = $projectId ? Project::query()->find($projectId) : null;
                abort_unless($person && $member && ($project ? $this->access->canAccessProject($person, $scope, $project) : $this->access->canAccessUnprojected($person, $scope)), 422, 'Исполнитель недоступен для этого проекта.');
            }
            $taskIds = $data['task_ids'] ?? ($item->exists ? $item->tasks()->pluck('tasks.id')->all() : []);
            $month = $data['month'] ?? $item->month;
            $syncTasks = array_key_exists('task_ids', $data) || array_key_exists('task_dates', $data) || $month !== $item->month || $projectId !== $item->project_id || ! $item->exists;
            if ($syncTasks) {
                $tasks = $this->tasks($actor, $scope)->whereIn('tasks.id', $taskIds)->where('project_id', $projectId)->lockForUpdate()->get();
                abort_unless($tasks->count() === count($taskIds), 422, 'Связывайте доступные задачи того же проекта. Для смены проекта сначала уберите прежние связи.');
                foreach ($tasks as $task) {
                    $date = $data['task_dates'][$task->id] ?? $task->due_at?->toDateString();
                    if (! $date || substr($date, 0, 7) !== $month) {
                        throw ValidationException::withMessages(['task_dates.'.$task->id => 'Укажите дату задачи в календаре в пределах месяца плана.']);
                    }
                    if ($date !== $task->due_at?->toDateString()) {
                        abort_unless($this->access->canAccessTask($actor, $scope, $task, 'task.update'), 403);
                        $previousDate = $task->due_at;
                        $task->update(['due_at' => $date.' 12:00:00']);
                        ActivityLog::query()->create(['scope_id' => $scope->id, 'actor_id' => $actor->id, 'subject_type' => 'task', 'subject_id' => $task->id,
                            'action' => 'task.planner_rescheduled', 'before' => ['due_at' => $previousDate], 'after' => ['due_at' => $task->due_at], 'context' => $audit]);
                    }
                }
            }
            $completed = $data['completed'] ?? ($item->completed_at !== null);
            unset($data['task_ids'], $data['task_dates'], $data['completed']);
            $item->fill($data);
            $item->scope_id = $scope->id;
            $item->created_by ??= $actor->id;
            if ($completed && ! $item->completed_at) {
                $item->completed_at = now();
                $item->completed_by = $actor->id;
            }
            if (! $completed) {
                $item->completed_at = null;
                $item->completed_by = null;
            }
            $item->save();
            if ($syncTasks) {
                $item->tasks()->sync($taskIds);
            }
            ActivityLog::query()->create(['scope_id' => $scope->id, 'actor_id' => $actor->id, 'subject_type' => 'plan_item', 'subject_id' => $item->id,
                'action' => $before ? 'plan.updated' : 'plan.created', 'before' => $before, 'after' => [...$item->toArray(), 'task_ids' => $taskIds], 'context' => $audit]);

            return $item;
        });
    }
}
