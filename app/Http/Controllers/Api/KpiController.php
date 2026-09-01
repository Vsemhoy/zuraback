<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreKpiRequest;
use App\Http\Requests\Api\UpdateKpiRequest;
use App\Models\Kpi;
use App\Models\Scope;
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
            ->get(['id', 'assignee_id', 'kpi_id']);
        $counts = $tasks->groupBy(fn ($task) => $task->assignee_id.'|'.$task->kpi_id)->map->count();
        $targets = $this->targets($scope);
        $people = $scope->members()->where('is_active', true)->with('user:id,name,position,type,status')->get()
            ->filter(fn ($membership) => $membership->user && ! $membership->user->isAgent())
            ->map(function ($membership) use ($areas, $counts, $targets): array {
                $rows = $areas->map(function ($area) use ($counts, $membership): array {
                    $completed = (int) ($counts[$membership->user_id.'|'.$area->id] ?? 0);
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
                    ];
                });
                $salaryPoints = (int) $rows->where('kind', 'salary')->sum('awarded_points');
                $bonusPoints = (int) $rows->where('kind', 'bonus')->sum('awarded_points');

                return [
                    'user' => $membership->user,
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
