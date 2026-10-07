<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreKpiRequest;
use App\Http\Requests\Api\UpdateKpiRequest;
use App\Models\Kpi;
use App\Models\Scope;
use App\Models\User;
use App\Services\ContractorAccessService;
use App\Services\ContractorContext;
use App\Services\KpiProfileService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource as LaravelJsonResource;
use Illuminate\Http\Response;

class KpiController extends Controller
{
    public function __construct(private readonly ContractorContext $context, private readonly KpiProfileService $profiles) {}

    public function index(Request $request, Scope $scope): AnonymousResourceCollection
    {
        if ($request->filled('user_id')) {
            $data = $request->validate(['user_id' => ['required', 'ulid'], 'month' => ['required', 'date_format:Y-m']]);
            abort_unless($scope->owner_id === $data['user_id'] || $scope->members()->where('user_id', $data['user_id'])->where('is_active', true)->exists(), 422);

            return LaravelJsonResource::collection(collect($this->profiles->resolve($scope, $data['user_id'], $data['month'])['items'])->filter(fn ($item) => $request->boolean('include_inactive') || $item['is_active'])->values());
        }
        $query = $scope->kpis()
            ->withCount([
                'tasks',
                'tasks as completed_tasks_count' => fn ($tasks) => $tasks->where('status', 'done'),
            ])
            ->orderBy('sort_order')
            ->orderBy('name');

        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        return LaravelJsonResource::collection($query->get());
    }

    public function store(StoreKpiRequest $request, Scope $scope): LaravelJsonResource
    {
        $kpi = $this->profiles->changeDefaults($scope, function (Scope $lockedScope) use ($request) {
            $data = $request->validated();
            $data['sort_order'] ??= ((int) $lockedScope->kpis()->max('sort_order')) + 1;

            return $lockedScope->kpis()->create([
                ...$data,
                'created_by' => $this->context->actor($request)->id,
            ]);
        });

        return new LaravelJsonResource($kpi->loadCount(['tasks']));
    }

    public function stats(Request $request, Scope $scope): LaravelJsonResource
    {
        $filters = $request->validate([
            'user_id' => ['nullable', 'ulid'],
            'month' => ['sometimes', 'date_format:Y-m'],
            'completion' => ['sometimes', 'in:completed,incomplete,all'],
        ]);
        $completion = $filters['completion'] ?? 'completed';
        $userId = $filters['user_id'] ?? null;
        if ($userId !== null) {
            $belongsToScope = $scope->owner_id === $userId || $scope->members()->where('user_id', $userId)->where('is_active', true)->exists();
            abort_unless($belongsToScope && User::query()->whereKey($userId)->whereIn('type', ['real', 'virtual'])->exists(), 422, 'The selected person is unavailable in this scope.');
            abort_unless(User::query()->whereKey($userId)->where('is_executor', true)->exists(), 422, 'The selected person is not an executor.');
        }
        $month = CarbonImmutable::createFromFormat('!Y-m', (string) $request->input('month', now()->format('Y-m')));
        abort_if($month === false, 422, 'Month must use YYYY-MM format.');
        $start = $month->startOfMonth();
        $end = $month->endOfMonth();
        $tasks = app(ContractorAccessService::class)->constrainTasks($scope->tasks()->getQuery(), $this->context->actor($request), $scope)->includedInReports()
            ->whereNotNull('assignee_id')
            ->whereNotNull('kpi_id')
            ->whereBetween('due_at', [$start, $end])
            ->whereNotIn('status', ['cancelled'])
            ->when($userId, fn ($query) => $query->where('assignee_id', $userId))
            ->with('project:id,title,key,color')
            ->orderBy('due_at')->orderBy('id')
            ->get(['id', 'project_id', 'assignee_id', 'kpi_id', 'task_key', 'title', 'status', 'due_at', 'completed_at']);
        $tasksByArea = $tasks->groupBy(fn ($task) => $task->assignee_id.'|'.$task->kpi_id);
        $targets = $this->targets($scope);
        $versions = $this->profiles->versions($scope);
        $people = User::query()
            ->whereIn('type', ['real', 'virtual'])
            ->where('is_executor', true)
            ->where(fn ($query) => $query->whereKey($scope->owner_id)->orWhereHas('scopeMemberships', fn ($members) => $members->where('scope_id', $scope->id)->where('is_active', true)))
            ->when($userId, fn ($query) => $query->whereKey($userId))
            ->orderBy('name')
            ->get(['id', 'name', 'position', 'type', 'status'])
            ->map(function (User $person) use ($scope, $month, $tasksByArea, $completion, $versions): array {
                $profile = $this->profiles->resolve($scope, $person->id, $month->format('Y-m'), $versions);
                $targets = $profile['targets'];
                $areas = collect($profile['items'])->map(fn ($item) => (object) $item);
                $rows = $areas->map(function ($area) use ($tasksByArea, $person, $completion): array {
                    $areaTasks = $tasksByArea->get($person->id.'|'.$area->id, collect());
                    $completed = $areaTasks->where('status', 'done')->count();
                    $visibleTasks = $areaTasks->filter(fn ($task): bool => match ($completion) {
                        'completed' => $task->status === 'done',
                        'incomplete' => $task->status !== 'done',
                        default => true,
                    });
                    $qualified = $completed >= $area->minimum_completed_tasks;

                    return [
                        'id' => $area->id,
                        'name' => $area->name,
                        'kind' => $area->kind,
                        'points' => $area->points,
                        'minimum_completed_tasks' => $area->minimum_completed_tasks,
                        'completed_tasks' => $completed,
                        'qualified' => $qualified,
                        'awarded_points' => $qualified ? $area->points : 0,
                        'tasks' => $visibleTasks->map(fn ($task): array => [
                            'id' => $task->id,
                            'task_key' => $task->task_key,
                            'title' => $task->title,
                            'status' => $task->status,
                            'due_at' => $task->due_at,
                            'completed_at' => $task->completed_at,
                            'project' => $task->project,
                        ])->values(),
                    ];
                });
                $salaryPoints = (int) $rows->where('kind', 'salary')->sum('awarded_points');
                $bonusPoints = (int) $rows->where('kind', 'bonus')->sum('awarded_points');

                return [
                    'user' => $person,
                    'salary_points' => $salaryPoints,
                    'salary_target' => $targets['salary_target_points'],
                    'salary_progress' => min(100, (int) round($salaryPoints / max(1, $targets['salary_target_points']) * 100)),
                    'bonus_points' => $bonusPoints,
                    'bonus_target' => $targets['bonus_target_points'],
                    'payable_bonus_percent' => min($targets['bonus_cap_percent'], $bonusPoints),
                    'areas' => $rows->filter(fn (array $row): bool => $row['tasks']->isNotEmpty())->values(),
                ];
            })->values();

        return new LaravelJsonResource([
            'month' => $month->format('Y-m'),
            'targets' => $targets,
            'people' => $people,
        ]);
    }

    public function settings(Request $request, Scope $scope): LaravelJsonResource
    {
        return new LaravelJsonResource([...$this->targets($scope), 'can_manage' => app(ContractorAccessService::class)->allows($this->context->actor($request), $scope, 'contractor.manage')]);
    }

    public function updateSettings(Request $request, Scope $scope): LaravelJsonResource
    {
        $targets = $request->validate([
            'salary_target_points' => ['required', 'integer', 'between:1,1000'],
            'bonus_target_points' => ['required', 'integer', 'between:1,1000'],
            'bonus_cap_percent' => ['required', 'integer', 'between:0,100'],
        ]);
        $this->profiles->changeDefaults($scope, fn (Scope $lockedScope) => $lockedScope->update(['settings' => [...($lockedScope->settings ?? []), 'kpi' => $targets]]));

        return new LaravelJsonResource($targets);
    }

    public function update(UpdateKpiRequest $request, Scope $scope, Kpi $kpi): LaravelJsonResource
    {
        abort_unless($kpi->scope_id === $scope->id, 404);
        $this->profiles->changeDefaults($scope, fn () => $kpi->update($request->validated()));

        return new LaravelJsonResource($kpi->fresh()->loadCount(['tasks']));
    }

    public function destroy(Scope $scope, Kpi $kpi): Response
    {
        abort_unless($kpi->scope_id === $scope->id, 404);
        $this->profiles->changeDefaults($scope, fn () => $kpi->delete());

        return response()->noContent();
    }

    private function targets(Scope $scope): array
    {
        return [
            'salary_target_points' => (int) data_get($scope->settings, 'kpi.salary_target_points', 100),
            'bonus_target_points' => (int) data_get($scope->settings, 'kpi.bonus_target_points', 75),
            'bonus_cap_percent' => (int) data_get($scope->settings, 'kpi.bonus_cap_percent', 75),
        ];
    }
}
