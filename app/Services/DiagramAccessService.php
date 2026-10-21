<?php

namespace App\Services;

use App\Models\Diagram;
use App\Models\PlanItem;
use App\Models\Project;
use App\Models\Scope;
use App\Models\Thing;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DiagramAccessService
{
    public const SUBJECTS = [...DarklogLinkService::TARGETS, 'user' => User::class, 'thing' => Thing::class];

    public function __construct(private readonly ContractorAccessService $access, private readonly DarklogLinkService $targets,
        private readonly FilerAccessService $files, private readonly PlanItemService $plans, private readonly EquipAccessService $equip) {}

    public function target(User $actor, Scope $scope, string $type, string $id): ?Model
    {
        if ($type === 'thing') {
            $thing = Thing::find($id);

            return $thing && $this->equip->thing($actor, $scope, $thing) ? $thing : null;
        }
        if ($type === 'user') {
            $person = User::query()->find($id);

            return $person && $this->files->subject($actor, $scope, $person) ? $person : null;
        }
        $target = $this->targets->resolve($actor, $scope, $type, $id);
        if ($target instanceof PlanItem && (! $this->access->allows($actor, $scope, 'report.view')
            || ! $this->plans->visible($actor, $scope)->whereKey($id)->exists())) {
            return null;
        }

        return $target;
    }

    public function describe(string $type, Model $target): array
    {
        if ($target instanceof Thing) {
            return ['id' => $target->id, 'type' => 'thing', 'title' => $target->name, 'code' => $target->inventory_number, 'href' => '/equip?item='.$target->id];
        }

        return $target instanceof User ? ['id' => $target->id, 'type' => 'user', 'title' => $target->name, 'code' => null, 'href' => '/contractors']
            : $this->targets->describe($type, $target);
    }

    public function candidates(User $actor, Scope $scope, string $type, string $term): array
    {
        if ($type === 'thing') {
            return $this->equip->constrain(Thing::query(), $actor, $scope)
                ->where(fn (Builder $q) => $q->where('name', 'like', '%'.$term.'%')->orWhere('inventory_number', 'like', '%'.$term.'%'))
                ->orderBy('name')->orderBy('id')->limit(30)->get()->map(fn (Thing $thing): array => $this->describe('thing', $thing))->all();
        }
        if ($type === 'user') {
            return User::query()->where(fn (Builder $q) => $q->whereKey($scope->owner_id)
                ->orWhereIn('id', $scope->members()->where('is_active', true)->select('user_id')))
                ->where('name', 'like', '%'.$term.'%')->orderBy('name')->limit(100)->get()
                ->filter(fn (User $person): bool => $this->files->subject($actor, $scope, $person))->take(30)
                ->map(fn (User $person): array => $this->describe('user', $person))->values()->all();
        }

        return array_values(array_filter($this->targets->candidates($actor, $scope, $type, $term),
            fn (array $row): bool => $this->target($actor, $scope, $type, $row['id']) !== null));
    }

    public function visible(User $actor, Scope $scope): Builder
    {
        $projects = $this->access->constrainProjects(Project::query()->where('scope_id', $scope->id), $actor, $scope)->select('id');
        $query = Diagram::query()->where('scope_id', $scope->id)
            ->where(fn (Builder $q) => $q->where('visibility', 'scope')->orWhere('created_by', $actor->id))
            ->where(function (Builder $q) use ($projects, $actor, $scope): void {
                $q->whereIn('project_id', $projects);
                if ($this->access->canAccessUnprojected($actor, $scope)) {
                    $q->orWhereNull('project_id');
                }
            });

        return $this->access->allows($actor, $scope, 'task.view') ? $query : $query->whereRaw('1 = 0');
    }

    public function can(User $actor, Scope $scope, Diagram $diagram, string $ability = 'task.view'): bool
    {
        return $this->access->allows($actor, $scope, $ability)
            && $this->visible($actor, $scope)->whereKey($diagram->id)->exists()
            && ($ability === 'task.view' || $diagram->created_by === $actor->id || $scope->owner_id === $actor->id);
    }

    public function canEdit(User $actor, Scope $scope, Diagram $diagram): bool
    {
        return $this->can($actor, $scope, $diagram)
            && $this->access->allows($actor, $scope, 'task.update')
            && ($diagram->created_by === $actor->id || $scope->owner_id === $actor->id
                || ($diagram->allow_member_editing && $this->projectAllowed($actor, $scope, $diagram->project_id)));
    }

    public function projectAllowed(User $actor, Scope $scope, ?string $projectId): bool
    {
        if ($projectId === null) {
            return $this->access->canAccessUnprojected($actor, $scope);
        }
        $project = Project::query()->find($projectId);

        return $project !== null && $this->access->canAccessProject($actor, $scope, $project, 'task.update');
    }
}
