<?php

namespace App\Services;

use App\Models\Fact;
use App\Models\Project;
use App\Models\Scope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class PrivateRecordAccessService
{
    private array $grants = [];

    private ?Request $request = null;

    private array $projectAccess = [];

    private function allowed(User $actor, Scope $scope, string $ability): bool
    {
        if ($this->request !== request()) {
            $this->request = request();
            $this->grants = [];
            $this->projectAccess = [];
        }
        $key = $actor->id.':'.$scope->id.':'.$ability;

        return $this->grants[$key] ??= $this->access->allows($actor, $scope, $ability);
    }

    private function projectAllowed(User $actor, Scope $scope, string $id, string $ability): bool
    {
        $key = $actor->id.':'.$scope->id.':'.$id.':'.$ability;

        return $this->projectAccess[$key] ??= $this->projects($actor, $scope, $ability)->whereKey($id)->exists();
    }

    public function __construct(private readonly ContractorAccessService $access) {}

    public function ability(Model|string $record): string
    {
        return $record === Fact::class || $record instanceof Fact ? 'fact' : 'contact';
    }

    public function projects(User $actor, Scope $scope, string $ability): Builder
    {
        $query = Project::where('scope_id', $scope->id);
        if (! $this->allowed($actor, $scope, $ability)) {
            return $query->whereRaw('1 = 0');
        }
        if ($scope->owner_id === $actor->id) {
            return $query;
        }
        $member = $this->access->membership($actor, $scope);
        if (! $member || $member->project_access_mode === 'none') {
            return $query->whereRaw('1 = 0');
        }
        $query->where(fn (Builder $q) => $q->where('visibility', '!=', 'private')->orWhere('created_by', $actor->id));
        $query->whereDoesntHave('members', fn (Builder $q) => $q->where('user_id', $actor->id)->where(fn (Builder $q) => $q->where('is_active', false)->orWhereJsonContains('permissions->deny', '*')->orWhereJsonContains('permissions->deny', $ability)));
        if ($member->project_access_mode !== 'all') {
            $query->whereHas('members', fn (Builder $q) => $q->where('user_id', $actor->id)->where('is_active', true));
        }

        return $query;
    }

    public function query(string $class, User $actor, Scope $scope, string $action = 'view'): Builder
    {
        $ability = $this->ability($class).'.'.$action;
        $query = $class::where('scope_id', $scope->id);
        if (! $this->allowed($actor, $scope, $ability)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(fn (Builder $q) => $q->where('visibility', '!=', 'private')->orWhere('created_by', $actor->id))
            ->where(fn (Builder $q) => $q->whereNull('project_id')->orWhereIn('project_id', $this->projects($actor, $scope, $ability)->select('id')));
    }

    public function read(User $actor, Scope $scope, Model $record): bool
    {
        $ability = $this->ability($record).'.view';

        return $record->scope_id === $scope->id && $this->allowed($actor, $scope, $ability)
            && ($record->visibility !== 'private' || $record->created_by === $actor->id)
            && (! $record->project_id || $this->projectAllowed($actor, $scope, $record->project_id, $ability));
    }

    public function manage(User $actor, Scope $scope, Model $record): bool
    {
        return $this->read($actor, $scope, $record)
            && ($record->created_by === $actor->id || $scope->owner_id === $actor->id)
            && $this->allowed($actor, $scope, $this->ability($record).'.write')
            && (! $record->project_id || $this->projectAllowed($actor, $scope, $record->project_id, $this->ability($record).'.write'));
    }

    public function write(User $actor, Scope $scope, Model $record): bool
    {
        return $this->read($actor, $scope, $record)
            && ($record->created_by === $actor->id || $scope->owner_id === $actor->id || $record->allow_member_editing)
            && $this->allowed($actor, $scope, $this->ability($record).'.write')
            && (! $record->project_id || $this->projectAllowed($actor, $scope, $record->project_id, $this->ability($record).'.write'));
    }

    public function reveal(User $actor, Scope $scope, Fact $fact): bool
    {
        return $this->read($actor, $scope, $fact) && $this->allowed($actor, $scope, 'fact.reveal')
            && (! $fact->project_id || $this->projectAllowed($actor, $scope, $fact->project_id, 'fact.reveal'));
    }

    public function validate(User $actor, Scope $scope, string $class, array $data, ?Model $record = null): void
    {
        if ($class === Fact::class) {
            $from = array_key_exists('valid_from', $data) ? $data['valid_from'] : $record?->valid_from;
            $to = array_key_exists('valid_to', $data) ? $data['valid_to'] : $record?->valid_to;
            if ($from && $to && Carbon::parse($to)->lt(Carbon::parse($from))) {
                throw ValidationException::withMessages(['valid_to' => 'Дата окончания не может быть раньше начала.']);
            }
        }
        $visibility = $data['visibility'] ?? $record?->visibility ?? 'private';
        $project = array_key_exists('project_id', $data) ? $data['project_id'] : $record?->project_id;
        if ($visibility === 'project' && ! $project) {
            throw ValidationException::withMessages(['project_id' => 'Выберите проект для проектной видимости.']);
        }
        if ($project && ! $this->projects($actor, $scope, $this->ability($class).'.write')->whereKey($project)->exists()) {
            throw ValidationException::withMessages(['project_id' => 'Проект недоступен в этом скоупе.']);
        }
        if ($record && array_intersect(array_keys($data), ['visibility', 'project_id', 'allow_member_editing']) && ! $this->manage($actor, $scope, $record)) {
            abort(403, 'Только владелец записи управляет её доступом.');
        }
    }
}
