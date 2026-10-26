<?php

namespace App\Services;

use App\Models\Book;
use App\Models\Darklog;
use App\Models\Event;
use App\Models\FilerFile;
use App\Models\LedgerTransaction;
use App\Models\Project;
use App\Models\Scope;
use App\Models\Task;
use App\Models\Thing;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class FilerAccessService
{
    public const SUBJECTS = ['thing' => Thing::class, 'task' => Task::class, 'event' => Event::class, 'book' => Book::class, 'project' => Project::class, 'user' => User::class, 'darklog' => Darklog::class, 'ledger_transaction' => LedgerTransaction::class];

    public function __construct(private readonly ContractorAccessService $access, private readonly EquipAccessService $equip, private readonly LedgerAccessService $ledger) {}

    public function subject(User $actor, Scope $scope, ?Model $subject, bool $write = false): bool
    {
        if ($subject === null || ! ScopeModuleService::entityEnabled($scope, $subject)) {
            return false;
        }
        if ($subject instanceof User) {
            return ($scope->owner_id === $subject->id || $scope->members()->where('user_id', $subject->id)->where('is_active', true)->exists())
                && ($subject->id === $actor->id || $this->access->canManageContractor($actor, $scope, $subject));
        }
        if ($subject->scope_id !== $scope->id) {
            return false;
        }
        if ($subject instanceof LedgerTransaction) {
            $subject->loadMissing(['account', 'targetAccount']);
            $ability = $write ? 'ledger.write' : 'ledger.view';

            // Receipts may reveal both sides of a transfer, so require access to both accounts.
            return ! $subject->trashed() && $subject->account
                && $this->ledger->account($actor, $scope, $subject->account, $ability)
                && (! $subject->target_account_id || ($subject->targetAccount && $this->ledger->account($actor, $scope, $subject->targetAccount, $ability)))
                && (! $write || (! $subject->account->is_archived && $subject->account->balance_is_known
                    && ! $subject->targetAccount?->is_archived && ($subject->targetAccount?->balance_is_known ?? true)
                    && (! in_array($subject->kind, LedgerTransaction::ADJUSTMENTS, true)
                        || $this->ledger->transaction($actor, $scope, $subject, 'ledger.manage'))));
        }
        if ($subject instanceof Thing) {
            return $this->equip->thing($actor, $scope, $subject, $write ? 'equip.update' : 'equip.view');
        }
        $ability = $write ? 'task.update' : 'task.view';
        if ($subject instanceof Darklog) {
            $subject->loadMissing('project');

            return $this->access->allows($actor, $scope, $ability)
                && ($subject->project_id === null ? $this->access->canAccessUnprojected($actor, $scope)
                    : ($subject->project !== null && $this->access->canAccessProject($actor, $scope, $subject->project, $ability)));
        }
        if ($subject instanceof Task) {
            $allowed = $this->access->canAccessTask($actor, $scope, $subject, $ability);

            return $allowed && (! $write || ! in_array($subject->status, ['done', 'cancelled'], true));
        }
        if ($subject instanceof Project) {
            return $this->access->canAccessProject($actor, $scope, $subject, $ability);
        }
        if ($subject instanceof Book) {
            return $this->access->canAccessBook($actor, $scope, $subject->loadMissing('project'))
                && (! $write || $this->access->allows($actor, $scope, 'book.update'));
        }
        if ($subject instanceof Event) {
            $subject->loadMissing('project');

            return ($subject->visibility !== 'private' || $subject->created_by === $actor->id || $scope->owner_id === $actor->id)
                && (! $write || ! $subject->is_locked || $subject->created_by === $actor->id || $scope->owner_id === $actor->id)
                && ($subject->project_id === null
                    ? $this->access->allows($actor, $scope, $ability)
                    : ($subject->project !== null && $this->access->canAccessProject($actor, $scope, $subject->project, $ability)));
        }

        return false;
    }

    public function file(User $actor, Scope $scope, FilerFile $file, bool $write = false): bool
    {
        if ($file->scope_id !== $scope->id
            || ($file->visibility === 'private' && $file->created_by !== $actor->id)
            || ($write && $file->created_by !== $actor->id && $scope->owner_id !== $actor->id)) {
            return false;
        }
        $file->loadMissing('attachments.subject');
        if ($file->container !== 'files' && ($file->attachments->isEmpty() || $file->attachments->contains(fn ($link): bool => $link->subject === null))) {
            return false;
        }
        if ($file->attachments->isEmpty()) {
            return $this->access->allows($actor, $scope, $write ? 'task.update' : 'task.view');
        }

        return $file->attachments->every(fn ($attachment): bool => $attachment->subject === null
            ? (($file->created_by === $actor->id || $scope->owner_id === $actor->id)
                && $this->access->allows($actor, $scope, $write ? 'task.update' : 'task.view'))
            : $this->subject($actor, $scope, $attachment->subject, $write));
    }
}
