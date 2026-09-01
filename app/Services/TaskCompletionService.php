<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;

class TaskCompletionService
{
    /** @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function apply(Task $task, array $changes, User $actor): array
    {
        if (! array_key_exists('status', $changes)) {
            return $changes;
        }

        if ($changes['status'] !== 'done') {
            $changes['completed_at'] = null;

            return $changes;
        }

        $changes['completed_at'] = $task->completed_at ?? now();
        $assigneeId = array_key_exists('assignee_id', $changes)
            ? $changes['assignee_id']
            : $task->assignee_id;

        if ($assigneeId === null && ! $actor->isAgent()) {
            $changes['assignee_id'] = $actor->id;
        }

        return $changes;
    }
}
