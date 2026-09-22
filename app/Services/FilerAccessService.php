<?php

namespace App\Services;

use App\Models\Book;
use App\Models\Event;
use App\Models\FilerFile;
use App\Models\Project;
use App\Models\Scope;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class FilerAccessService
{
    public const SUBJECTS = ['task' => Task::class, 'event' => Event::class, 'book' => Book::class, 'project' => Project::class, 'user' => User::class];

    public function __construct(private readonly ContractorAccessService $access) {}

    public function subject(User $actor, Scope $scope, ?Model $subject, bool $write = false): bool
    {
        if ($subject === null) {
            return false;
        }
        if ($subject instanceof User) {
            return $scope->members()->where('user_id', $subject->id)->where('is_active', true)->exists()
                && ($subject->id === $actor->id || $this->access->canManageContractor($actor, $scope, $subject));
        }
        if ($subject->scope_id !== $scope->id) {
            return false;
        }
        $ability = $write ? 'task.update' : 'task.view';
        if ($subject instanceof Task) {
            return $this->access->canAccessTask($actor, $scope, $subject, $ability);
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
        if ($file->attachments->isEmpty()) {
            return $this->access->allows($actor, $scope, $write ? 'task.update' : 'task.view');
        }

        return $file->attachments->every(fn ($attachment): bool => $attachment->subject === null
            ? (($file->created_by === $actor->id || $scope->owner_id === $actor->id)
                && $this->access->allows($actor, $scope, $write ? 'task.update' : 'task.view'))
            : $this->subject($actor, $scope, $attachment->subject, $write));
    }
}
