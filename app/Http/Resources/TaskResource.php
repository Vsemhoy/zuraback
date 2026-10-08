<?php

namespace App\Http\Resources;

use App\Models\Scope;
use App\Services\KpiProfileService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);
        if ($this->kpi_id && $this->assignee_id) {
            $scope = $request->route('scope');
            if ($scope instanceof Scope && $scope->id === $this->scope_id) {
                $profiles = app(KpiProfileService::class);
                $cacheKey = 'task-kpi-versions-'.$scope->id;
                $versions = $request->attributes->get($cacheKey);
                if ($versions === null) {
                    $versions = $profiles->versions($scope);
                    $request->attributes->set($cacheKey, $versions);
                }
                $profile = $profiles->resolve($scope, $this->assignee_id, ($this->due_at ?? now())->format('Y-m'), $versions);
                $item = collect($profile['items'])->firstWhere('id', $this->kpi_id);
                $data['kpi_profile_eligible'] = $item !== null;
                if ($item) {
                    $data['kpi'] = [...($data['kpi'] ?? []), ...$item];
                }
            }
        }

        return [
            ...$data,
            'planner_tails' => $this->whenLoaded('plannerTails', fn () => $this->plannerTails->map(fn ($tail): array => [
                'id' => $tail->id,
                'planned_on' => $tail->planned_on->format('Y-m-d'),
            ])->values()),
        ];
    }
}
