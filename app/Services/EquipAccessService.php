<?php

namespace App\Services;

use App\Models\Scope;
use App\Models\Thing;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class EquipAccessService
{
    public function __construct(private readonly ContractorAccessService $access) {}

    public function allDepartments(User $actor, Scope $scope): bool
    {
        return $this->access->allows($actor, $scope, 'equip.access_all');
    }

    public function department(User $actor, Scope $scope, ?string $departmentId): bool
    {
        if ($this->allDepartments($actor, $scope)) {
            return true;
        }
        $membership = $this->access->membership($actor, $scope);

        return $departmentId !== null && $membership?->department_id === $departmentId;
    }

    public function thing(User $actor, Scope $scope, Thing $thing, string $ability = 'equip.view'): bool
    {
        return $thing->scope_id === $scope->id
            && $this->access->allows($actor, $scope, 'equip.view')
            && $this->access->allows($actor, $scope, $ability)
            && $this->department($actor, $scope, $thing->department_id);
    }

    public function constrain(Builder $query, User $actor, Scope $scope): Builder
    {
        $query->where('scope_id', $scope->id);
        if (! $this->access->allows($actor, $scope, 'equip.view')) {
            return $query->whereRaw('1 = 0');
        }
        if (! $this->allDepartments($actor, $scope)) {
            $departmentId = $this->access->membership($actor, $scope)?->department_id;
            $departmentId ? $query->where('department_id', $departmentId) : $query->whereRaw('1 = 0');
        }

        return $query;
    }
}
