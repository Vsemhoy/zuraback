<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\LedgerAccount;
use App\Models\LedgerCategory;
use App\Models\LedgerTransaction;
use App\Models\Project;
use App\Models\Scope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class LedgerAccessService
{
    public function __construct(private readonly ContractorAccessService $access) {}

    public function projects(User $actor, Scope $scope, string $ability = 'ledger.view'): Builder
    {
        $query = Project::where('scope_id', $scope->id);
        if (! $this->access->allows($actor, $scope, 'ledger.view') || ! $this->access->allows($actor, $scope, $ability)) {
            return $query->whereRaw('1 = 0');
        }
        if ($scope->owner_id === $actor->id) {
            return $ability === 'ledger.view' ? $query->withTrashed() : $query;
        }
        $membership = $this->access->membership($actor, $scope);
        if (! $membership || $membership->project_access_mode === 'none') {
            return $query->whereRaw('1 = 0');
        }
        $query->where(fn (Builder $private) => $private->where('visibility', '!=', 'private')->orWhere('created_by', $actor->id));
        $query->whereDoesntHave('members', function (Builder $member) use ($actor, $ability): void {
            $member->where('user_id', $actor->id)->where(function (Builder $blocked) use ($ability): void {
                $blocked->where('is_active', false)->orWhereJsonContains('permissions->deny', '*')
                    ->orWhereJsonContains('permissions->deny', 'ledger.view')->orWhereJsonContains('permissions->deny', $ability);
            });
        });
        if ($membership->project_access_mode !== 'all') {
            $query->whereHas('members', fn (Builder $member) => $member->where('user_id', $actor->id)->where('is_active', true));
        }

        return $query;
    }

    public function accounts(User $actor, Scope $scope, string $ability = 'ledger.view'): Builder
    {
        return LedgerAccount::where('scope_id', $scope->id)->whereIn('project_id', $this->projects($actor, $scope, $ability)->select('id'));
    }

    public function account(User $actor, Scope $scope, LedgerAccount $account, string $ability = 'ledger.view'): bool
    {
        return $account->scope_id === $scope->id && $this->accounts($actor, $scope, $ability)->whereKey($account->id)->exists();
    }

    public function transaction(User $actor, Scope $scope, LedgerTransaction $transaction, string $ability = 'ledger.view'): bool
    {
        if ($transaction->scope_id !== $scope->id) {
            return false;
        }
        $source = $this->account($actor, $scope, $transaction->account, $ability);
        $target = $transaction->target_account_id ? $this->account($actor, $scope, $transaction->targetAccount, $ability) : false;

        return $ability === 'ledger.view' ? ($source || $target) : ($source && (! $transaction->target_account_id || $target));
    }

    public function categories(User $actor, Scope $scope): Builder
    {
        return LedgerCategory::where('scope_id', $scope->id)->whereIn('project_id', $this->projects($actor, $scope)->select('id'));
    }

    public function activity(User $actor, Scope $scope, ActivityLog $log): bool
    {
        if ($log->scope_id !== $scope->id) {
            return false;
        }
        if ($log->subject_type === 'ledger_transaction') {
            $transaction = LedgerTransaction::withTrashed()->where('scope_id', $scope->id)->with(['account', 'targetAccount'])->find($log->subject_id);

            return $transaction !== null && $this->transaction($actor, $scope, $transaction, 'ledger.manage');
        }
        if ($log->subject_type === 'ledger_account') {
            return $this->accounts($actor, $scope)->whereKey($log->subject_id)->exists();
        }

        return $log->subject_type === 'ledger_category' && $this->categories($actor, $scope)->whereKey($log->subject_id)->exists();
    }
}
