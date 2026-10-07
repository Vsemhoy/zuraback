<?php

namespace App\Services;

use App\Models\MonthlyReport;
use App\Models\MonthlyTaskPlan;
use App\Models\Project;
use App\Models\Scope;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class MonthlyReportService
{
    public function __construct(private readonly ContractorAccessService $access, private readonly MonthlyReportWorkbook $workbook, private readonly PlanItemService $plans, private readonly KpiProfileService $profiles) {}

    public function visibleTasks(User $actor, Scope $scope): Builder
    {
        $projectIds = $this->access->constrainProjects($scope->projects()->getQuery(), $actor, $scope)->get()
            ->filter(fn (Project $project): bool => $this->access->canAccessProject($actor, $scope, $project)
                && $this->access->canAccessProject($actor, $scope, $project, 'report.view'))->modelKeys();

        return Task::query()->includedInReports()->where('scope_id', $scope->id)->where(function (Builder $query) use ($projectIds, $actor, $scope): void {
            $query->whereIn('project_id', $projectIds);
            if ($this->access->canAccessUnprojected($actor, $scope)) {
                $query->orWhereNull('project_id');
            }
        });
    }

    public function build(User $actor, Scope $scope, array $filters): array
    {
        $month = CarbonImmutable::createFromFormat('!Y-m', $filters['month'], $filters['timezone']);
        $next = $month->addMonth();
        $personId = $filters['user_id'] ?? null;
        $query = $this->visibleTasks($actor, $scope)->where('status', 'done')
            ->where('completed_at', '>=', $month->utc())->where('completed_at', '<', $next->utc())
            ->when($personId, fn (Builder $query): Builder => $query->where('assignee_id', $personId))
            ->with(['planItems' => fn ($plans) => $plans->whereIn('plan_items.id', $this->plans->visible($actor, $scope, true)->select('plan_items.id')), 'monthlyPlans', 'project:id,title,key', 'assignee:id,name,type,is_executor', 'customer:id,name', 'kpi:id,scope_id,name,kind,points,minimum_completed_tasks'])
            ->orderBy('completed_at')->orderBy('id');
        $tasks = $query->limit(5001)->get();
        abort_if($tasks->count() > 5000, 422, 'Слишком много задач для одной выгрузки. Выберите сотрудника.');
        $people = User::query()->where(fn (Builder $query): Builder => $query->whereKey($scope->owner_id)
            ->orWhereHas('scopeMemberships', fn (Builder $members): Builder => $members->where('scope_id', $scope->id)->where('is_active', true)))
            ->whereIn('type', ['real', 'virtual'])->where('is_executor', true)->orderBy('name')->get(['id', 'name']);
        $knownPerson = $personId ? User::query()->find($personId) : null;
        abort_if($personId && ! $people->contains('id', $personId) && $tasks->isEmpty(), 422, 'Сотрудник недоступен в этом скоупе.');
        $completed = $tasks->map(fn (Task $task): array => $this->taskRow($task))->values();
        $versions = $this->profiles->versions($scope);
        $profileByUser = $people->mapWithKeys(fn ($person) => [$person->id => $this->profiles->resolve($scope, $person->id, $month->format('Y-m'), $versions)]);
        $qualified = $tasks->filter(fn (Task $task): bool => $task->assignee !== null && $task->assignee->is_executor && in_array($task->assignee->type, ['real', 'virtual'], true))
            ->groupBy(fn (Task $task): string => $task->assignee_id.'|'.$task->kpi_id)
            ->map(function ($group) use ($profileByUser): ?array {
                $task = $group->first();
                $kpi = collect($profileByUser->get($task->assignee_id)['items'] ?? [])->firstWhere('id', $task->kpi_id);
                if (! $kpi || $kpi['kind'] !== 'bonus' || $group->count() < $kpi['minimum_completed_tasks']) {
                    return null;
                }

                return ['user_id' => $task->assignee_id, 'user_name' => $task->assignee->name, 'kpi_id' => $task->kpi_id, 'name' => $kpi['name'],
                    'points' => $kpi['points'], 'minimum_completed_tasks' => $kpi['minimum_completed_tasks'],
                    'completed_tasks' => $group->count(), 'tasks' => $group->map(fn (Task $task): array => $this->taskRow($task))->values()->all()];
            })->filter()->values();
        $summaryPeople = $people->when($personId, fn ($rows) => $rows->where('id', $personId))->mapWithKeys(fn (User $person): array => [$person->id => $person->name]);
        foreach ($completed as $task) {
            $summaryPeople->put($task['assignee_id'] ?? '', $task['assignee_name']);
        }
        $summary = $summaryPeople->map(function (string $name, string $id) use ($completed, $qualified, $profileByUser): array {
            $cap = (int) ($profileByUser->get($id)['targets']['bonus_cap_percent'] ?? 75);
            $kpis = $qualified->where('user_id', $id);
            $points = (int) $kpis->sum('points');

            return ['user_id' => $id ?: null, 'name' => $name, 'completed_tasks' => $completed->filter(fn (array $task): bool => ($task['assignee_id'] ?? '') === $id)->count(),
                'qualified_kpis' => $kpis->count(), 'bonus_points' => $points, 'bonus_percent' => min($cap, $points)];
        })->values();
        $plans = MonthlyTaskPlan::query()->where('scope_id', $scope->id)->where('month', $next->format('Y-m'))
            ->whereIn('task_id', $this->visibleTasks($actor, $scope)->select('tasks.id'))
            ->when($personId, fn (Builder $query): Builder => $query->where('assignee_id', $personId))
            ->with(['task.project:id,title,key', 'task.assignee:id,name', 'task.customer:id,name', 'task.blockers' => fn ($query) => $query->whereNull('resolved_at'), 'assignee:id,name'])
            ->orderBy('created_at')->orderBy('id')->limit(5001)->get();
        abort_if($plans->count() > 5000, 422, 'Слишком большой план. Выберите сотрудника.');

        return [
            'schema_version' => 2, 'scope' => ['id' => $scope->id, 'name' => $scope->name],
            'month' => $month->format('Y-m'), 'plan_month' => $next->format('Y-m'), 'timezone' => $filters['timezone'],
            'person_id' => $personId, 'person_name' => $knownPerson?->name, 'generated_at' => now()->toISOString(),
            'bonus_cap_percent' => $this->profiles->resolve($scope, $personId, $month->format('Y-m'), $versions)['targets']['bonus_cap_percent'], 'summary' => $summary->all(), 'kpis' => $qualified->all(), 'completed' => $completed->all(),
            'people' => $people->toArray(),
            'plan_year' => (int) ($filters['plan_year'] ?? $month->year),
            'plan_items' => $this->plans->year($actor, $scope, (int) ($filters['plan_year'] ?? $month->year), $personId),
            'plan' => $plans->map(fn (MonthlyTaskPlan $plan): array => [
                'id' => $plan->id, 'month' => $plan->month, 'assignee_id' => $plan->assignee_id,
                'assignee_name' => $plan->assignee?->name ?? 'Без исполнителя', 'expected_result' => $plan->expected_result,
                'task' => $this->taskRow($plan->task),
                'blockers' => $plan->task->blockers->map(fn ($blocker): array => ['reason' => $blocker->reason, 'resolution_required' => $blocker->resolution_required])->all(),
            ])->all(),
        ];
    }

    private function taskRow(Task $task): array
    {
        return [
            'id' => $task->id, 'task_key' => $task->task_key, 'title' => $task->title, 'result' => $task->result,
            'planned' => ($task->relationLoaded('planItems') && $task->planItems->contains(fn ($plan) => $plan->project_id === $task->project_id))
                || ($task->relationLoaded('monthlyPlans') && $task->monthlyPlans->isNotEmpty()),
            'status' => $task->status, 'due_at' => $task->due_at?->toISOString(), 'project_id' => $task->project_id,
            'project_name' => $task->project ? $task->project->key.' · '.$task->project->title : 'Без проекта',
            'assignee_id' => $task->assignee_id, 'assignee_name' => $task->assignee?->name ?? 'Без исполнителя',
            'customer_name' => $task->customer?->name ?? '', 'completed_at' => $task->completed_at?->toISOString(),
        ];
    }

    public function archive(User $actor, Scope $scope, array $filters): MonthlyReport
    {
        $snapshot = $this->build($actor, $scope, $filters);
        $id = (string) Str::ulid();
        $path = $scope->id.'/'.$filters['month'].'/'.$id.'.xlsx';
        $disk = Storage::disk('reports');
        $temporary = tempnam(sys_get_temp_dir(), 'zur-report-');
        abort_if($temporary === false, 503, 'Не удалось подготовить файл отчёта.');
        try {
            $this->workbook->write($snapshot, $temporary);
            $size = filesize($temporary);
            $hash = hash_file('sha256', $temporary);
            $directory = $scope->id.'/'.$filters['month'];
            $disk->makeDirectory($directory);
            $freeBytes = disk_free_space($disk->path($directory));
            abort_if($freeBytes === false || $freeBytes - $size < (int) config('filer.reserve_bytes', 0), 507, 'Недостаточно свободного места для сохранения отчёта.');
            $stream = fopen($temporary, 'rb');
            try {
                if (! $disk->put($path, $stream)) {
                    throw new \RuntimeException('Не удалось сохранить отчёт.');
                }
            } finally {
                fclose($stream);
            }
            $report = new MonthlyReport([
                'scope_id' => $scope->id, 'created_by' => $actor->id, 'month' => $filters['month'],
                'person_id' => $snapshot['person_id'], 'person_name' => $snapshot['person_name'],
                'timezone' => $filters['timezone'], 'snapshot' => $snapshot, 'file_path' => $path,
                'sha256' => $hash, 'size_bytes' => $size,
            ]);
            $report->id = $id;
            $report->save();

            return $report;
        } catch (Throwable $error) {
            $disk->delete($path);
            throw $error;
        } finally {
            unlink($temporary);
        }
    }

    public function canDownload(User $actor, Scope $scope, MonthlyReport $report): bool
    {
        if ($report->scope_id !== $scope->id || $report->created_by !== $actor->id
            || ! $this->access->allows($actor, $scope, 'report.view') || ! $this->access->allows($actor, $scope, 'task.view')) {
            return false;
        }
        $tasks = collect($report->snapshot['completed'])->concat(collect($report->snapshot['plan'])->pluck('task'))->concat($report->snapshot['plan_items'] ?? []);
        foreach ($tasks->pluck('project_id')->unique() as $projectId) {
            if ($projectId === null) {
                if (! $this->access->canAccessUnprojected($actor, $scope)) {
                    return false;
                }
            } else {
                $project = Project::query()->find($projectId);
                if (! $project || ! $this->access->canAccessProject($actor, $scope, $project)
                    || ! $this->access->canAccessProject($actor, $scope, $project, 'report.view')) {
                    return false;
                }
            }
        }

        return true;
    }
}
