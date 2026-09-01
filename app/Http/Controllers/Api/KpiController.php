<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreKpiRequest;
use App\Http\Requests\Api\UpdateKpiRequest;
use App\Models\Kpi;
use App\Models\Scope;
use App\Models\User;
use App\Services\ContractorContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource as LaravelJsonResource;
use Illuminate\Http\Response;

class KpiController extends Controller
{
    public function __construct(private readonly ContractorContext $context) {}

    public function index(Request $request, Scope $scope): AnonymousResourceCollection
    {
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
        $data = $request->validated();
        $data['sort_order'] ??= ((int) $scope->kpis()->max('sort_order')) + 1;
        $kpi = $scope->kpis()->create([
            ...$data,
            'created_by' => $this->context->actor($request)->id,
        ]);

        return new LaravelJsonResource($kpi->loadCount(['tasks']));
    }

    public function stats(Request $request, Scope $scope): LaravelJsonResource
    {
        $filters = $request->validate(['user_id' => ['nullable', 'ulid']]);
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
        $areas = $scope->kpis()->orderBy('sort_order')->orderBy('name')->get();
        $tasks = $scope->tasks()
            ->where('status', 'done')
            ->whereNotNull('assignee_id')
            ->whereNotNull('kpi_id')
            ->whereBetween('completed_at', [$start, $end])
            ->when($userId, fn ($query) => $query->where('assignee_id', $userId))
            ->with('project:id,title,key,color')
            ->orderBy('completed_at')
            ->get(['id', 'project_id', 'assignee_id', 'kpi_id', 'task_key', 'title', 'completed_at']);
        $tasksByArea = $tasks->groupBy(fn ($task) => $task->assignee_id.'|'.$task->kpi_id);
        $targets = $this->targets($scope);
        $people = User::query()
            ->whereIn('type', ['real', 'virtual'])
            ->where('is_executor', true)
            ->where(fn ($query) => $query->whereKey($scope->owner_id)->orWhereHas('scopeMemberships', fn ($members) => $members->where('scope_id', $scope->id)->where('is_active', true)))
            ->when($userId, fn ($query) => $query->whereKey($userId))
            ->orderBy('name')
            ->get(['id', 'name', 'position', 'type', 'status'])
            ->map(function (User $person) use ($areas, $tasksByArea, $targets): array {
                $rows = $areas->map(function ($area) use ($tasksByArea, $person): array {
                    $completedTasks = $tasksByArea->get($person->id.'|'.$area->id, collect());
                    $completed = $completedTasks->count();
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
                        'tasks' => $completedTasks->map(fn ($task): array => [
                            'id' => $task->id,
                            'task_key' => $task->task_key,
                            'title' => $task->title,
                            'completed_at' => $task->completed_at,
                            'project' => $task->project,
                        ])->values(),
                    ];
                })->filter(fn (array $row): bool => $row['completed_tasks'] > 0)->values();
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
                    'areas' => $rows->values(),
                ];
            })->values();

        return new LaravelJsonResource([
            'month' => $month->format('Y-m'),
            'targets' => $targets,
            'people' => $people,
        ]);
    }

    public function settings(Scope $scope): LaravelJsonResource
    {
        return new LaravelJsonResource($this->targets($scope));
    }

    public function updateSettings(Request $request, Scope $scope): LaravelJsonResource
    {
        $targets = $request->validate([
            'salary_target_points' => ['required', 'integer', 'between:1,1000'],
            'bonus_target_points' => ['required', 'integer', 'between:1,1000'],
            'bonus_cap_percent' => ['required', 'integer', 'between:0,100'],
        ]);
        $scope->update(['settings' => [...($scope->settings ?? []), 'kpi' => $targets]]);

        return new LaravelJsonResource($targets);
    }

    public function update(UpdateKpiRequest $request, Scope $scope, Kpi $kpi): LaravelJsonResource
    {
        abort_unless($kpi->scope_id === $scope->id, 404);
        $kpi->update($request->validated());

        return new LaravelJsonResource($kpi->fresh()->loadCount(['tasks']));
    }

    public function destroy(Scope $scope, Kpi $kpi): Response
    {
        abort_unless($kpi->scope_id === $scope->id, 404);
        $kpi->delete();

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
