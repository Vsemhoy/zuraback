<?php

namespace App\Services;

use App\Models\KpiProfile;
use App\Models\Scope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class KpiProfileService
{
    public function defaults(Scope $scope): array
    {
        return ['salary_target_points' => (int) data_get($scope->settings, 'kpi.salary_target_points', 100), 'bonus_target_points' => (int) data_get($scope->settings, 'kpi.bonus_target_points', 75), 'bonus_cap_percent' => (int) data_get($scope->settings, 'kpi.bonus_cap_percent', 75)];
    }

    public function catalog(Scope $scope): array
    {
        return $scope->kpis()->orderBy('sort_order')->orderBy('name')->get()->map(fn ($kpi) => $kpi->only(['id', 'name', 'description', 'kind', 'points', 'minimum_completed_tasks', 'is_active']))->all();
    }

    public function versions(Scope $scope): Collection
    {
        return KpiProfile::where('scope_id', $scope->id)->orderByDesc('effective_month')->get();
    }

    public function resolve(Scope $scope, ?string $userId, string $month, ?Collection $versions = null): array
    {
        $versions ??= $this->versions($scope);
        $eligible = $versions->where('effective_month', '<=', $month);
        $profile = ($userId ? $eligible->firstWhere('profile_key', $userId) : null) ?? $eligible->firstWhere('profile_key', 'scope');
        if ($profile) {
            return ['targets' => $profile->targets, 'items' => $profile->items, 'effective_month' => $profile->effective_month, 'personal' => $profile->user_id !== null];
        }
        $scope->loadMissing('kpis');

        return ['targets' => $this->defaults($scope), 'items' => $scope->kpis->sortBy('sort_order')->map(fn ($kpi) => $kpi->only(['id', 'name', 'description', 'kind', 'points', 'minimum_completed_tasks', 'is_active']))->values()->all(), 'effective_month' => null, 'personal' => false];
    }

    public function changeDefaults(Scope $scope, callable $change): mixed
    {
        return DB::transaction(function () use ($scope, $change) {
            $lockedScope = Scope::query()->lockForUpdate()->findOrFail($scope->id);
            $this->freeze($lockedScope);
            $result = $change($lockedScope);
            $this->publishDefaults($lockedScope);

            return $result;
        });
    }

    public function freeze(Scope $scope): void
    {
        KpiProfile::firstOrCreate(['scope_id' => $scope->id, 'profile_key' => 'scope', 'effective_month' => '0000-01'], ['targets' => $this->defaults($scope), 'items' => $this->catalog($scope), 'created_by' => $scope->owner_id]);
    }

    public function publishDefaults(Scope $scope): void
    {
        KpiProfile::updateOrCreate(['scope_id' => $scope->id, 'profile_key' => 'scope', 'effective_month' => now()->format('Y-m')], ['targets' => $this->defaults($scope->fresh()), 'items' => $this->catalog($scope), 'created_by' => $scope->owner_id]);
    }
}
