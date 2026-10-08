<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Kpi;
use App\Models\KpiProfile;
use App\Models\Scope;
use App\Models\User;
use App\Services\ContractorAccessService;
use App\Services\ContractorContext;
use App\Services\KpiProfileService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class KpiProfileController extends Controller
{
    public function __construct(private readonly KpiProfileService $profiles, private readonly ContractorAccessService $access, private readonly ContractorContext $context) {}

    private function person(Scope $scope, string $id): void
    {
        abort_unless(User::whereKey($id)->whereIn('type', ['real', 'virtual'])->where('is_executor', true)->exists() && ($scope->owner_id === $id || $scope->members()->where('user_id', $id)->where('is_active', true)->exists()), 422, 'Выберите исполнителя этого скоупа.');
    }

    public function show(Request $request, Scope $scope, User $user): JsonResource
    {
        $this->person($scope, $user->id);
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);

        return new JsonResource([...$this->profiles->resolve($scope, $user->id, $data['month']), 'can_manage' => $this->access->allows($this->context->actor($request), $scope, 'contractor.manage')]);
    }

    public function update(Request $request, Scope $scope, User $user): JsonResource
    {
        $this->person($scope, $user->id);
        $data = $request->validate([
            'effective_month' => ['required', 'date_format:Y-m'],
            'targets' => ['required', 'array:salary_target_points,bonus_target_points,bonus_cap_percent'],
            'targets.salary_target_points' => ['required', 'integer', 'between:1,1000'],
            'targets.bonus_target_points' => ['required', 'integer', 'between:1,1000'],
            'targets.bonus_cap_percent' => ['required', 'integer', 'between:0,100'],
            'items' => ['present', 'array', 'max:100'],
            'items.*.id' => ['nullable', 'ulid', 'distinct'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.description' => ['nullable', 'string', 'max:10000'],
            'items.*.kind' => ['required', 'in:salary,bonus'],
            'items.*.points' => ['required', 'integer', 'between:0,1000'],
            'items.*.minimum_completed_tasks' => ['required', 'integer', 'between:1,1000'],
            'items.*.is_active' => ['required', 'boolean'],
        ]);
        abort_if($data['effective_month'] < now()->format('Y-m'), 422, 'Прошедшие месяцы защищены от изменения норм.');
        $actor = $this->context->actor($request);
        $profile = DB::transaction(function () use ($scope, $user, $data, $actor): KpiProfile {
            Scope::whereKey($scope->id)->lockForUpdate()->firstOrFail();
            $this->profiles->freeze($scope);
            $items = collect($data['items'])->map(function (array $item) use ($scope, $actor): array {
                if (! empty($item['id'])) {
                    abort_unless(Kpi::withTrashed()->where('scope_id', $scope->id)->whereKey($item['id'])->exists(), 422, 'KPI другого скоупа недоступен.');
                } else {
                    $kpi = $scope->kpis()->create([...$item, 'created_by' => $actor->id]);
                    $item['id'] = $kpi->id;
                }

                return $item;
            })->all();
            $profile = KpiProfile::updateOrCreate(['scope_id' => $scope->id, 'profile_key' => $user->id, 'effective_month' => $data['effective_month']], ['user_id' => $user->id, 'targets' => $data['targets'], 'items' => $items, 'created_by' => $actor->id]);
            ActivityLog::create(['scope_id' => $scope->id, 'actor_id' => $actor->id, 'subject_type' => 'user', 'subject_id' => $user->id, 'action' => 'kpi.profile_updated', 'after' => $profile->only(['effective_month', 'targets', 'items'])]);

            return $profile;
        });

        return new JsonResource($profile);
    }
}
