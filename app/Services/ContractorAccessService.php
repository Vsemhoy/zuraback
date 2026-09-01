<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Scope;
use App\Models\ScopeMember;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ContractorAccessService
{
    public const ABILITIES = [
        'contractor.manage',
        'task.view',
        'task.create',
        'task.update',
        'task.assign',
        'report.view',
        'report.write',
    ];

    /** @var array<string, array<int, string>> */
    private const ROLE_ABILITIES = [
        'admin' => self::ABILITIES,
        'member' => ['task.view', 'task.create', 'task.update', 'task.assign', 'report.view', 'report.write'],
        'observer' => ['task.view', 'report.view'],
    ];

    public function allows(User $user, Scope $scope, string $ability, ?Project $project = null): bool
    {
        if (! $user->is_active || $user->status !== 'active') {
            return false;
        }

        if ($user->isAgent() && $user->currentAccessToken() !== null && ! $user->tokenCan($ability)) {
            return false;
        }

        if ($scope->owner_id === $user->id) {
            return true;
        }

        $membership = $this->membership($user, $scope);

        if ($membership === null || $this->isDenied($membership->permissions, $ability)) {
            return false;
        }

        $allowed = $this->isAllowed($membership->permissions, $ability)
            || in_array($ability, self::ROLE_ABILITIES[$membership->role] ?? [], true);

        if (! $allowed || $project === null) {
            return $allowed;
        }

        if ($project->scope_id !== $scope->id || $membership->project_access_mode === 'none') {
            return false;
        }

        if ($membership->project_access_mode === 'all') {
            return true;
        }

        $projectMembership = $project->members()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        if ($projectMembership === null || $this->isDenied($projectMembership->permissions, $ability)) {
            return false;
        }

        return $this->isAllowed($projectMembership->permissions, $ability) || $allowed;
    }

    public function membership(User $user, Scope $scope): ?ScopeMember
    {
        return $scope->members()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();
    }

    public function canAccessUnprojected(User $user, Scope $scope): bool
    {
        if ($scope->owner_id === $user->id) {
            return true;
        }

        return $this->membership($user, $scope)?->project_access_mode === 'all';
    }

    /** @param Builder<Project> $query */
    public function constrainProjects(Builder $query, User $user, Scope $scope): Builder
    {
        if ($scope->owner_id === $user->id) {
            return $query;
        }

        $membership = $this->membership($user, $scope);

        if ($membership === null || $membership->project_access_mode === 'none') {
            return $query->whereRaw('1 = 0');
        }

        if ($membership->project_access_mode === 'restricted') {
            return $query->whereHas('members', fn (Builder $members): Builder => $members
                ->where('user_id', $user->id)
                ->where('is_active', true));
        }

        return $query;
    }

    /** @param Builder<Task> $query */
    public function constrainTasks(Builder $query, User $user, Scope $scope): Builder
    {
        if ($scope->owner_id === $user->id) {
            return $query;
        }

        $membership = $this->membership($user, $scope);

        if ($membership === null || $membership->project_access_mode === 'none') {
            return $query->whereRaw('1 = 0');
        }

        if ($membership->project_access_mode === 'restricted') {
            return $query->whereHas('project.members', fn (Builder $members): Builder => $members
                ->where('user_id', $user->id)
                ->where('is_active', true));
        }

        return $query;
    }

    /** @param array<string, mixed>|null $permissions */
    private function isAllowed(?array $permissions, string $ability): bool
    {
        return in_array('*', $permissions['allow'] ?? [], true)
            || in_array($ability, $permissions['allow'] ?? [], true);
    }

    /** @param array<string, mixed>|null $permissions */
    private function isDenied(?array $permissions, string $ability): bool
    {
        return in_array('*', $permissions['deny'] ?? [], true)
            || in_array($ability, $permissions['deny'] ?? [], true);
    }
}
