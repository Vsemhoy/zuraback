<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['task_id', 'created_by', 'assignee_id', 'completed_by', 'title', 'due_at', 'completed_at', 'sort_order', 'meta'])]
class TaskChecklistItem extends DomainModel
{
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id')->withTrashed();
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by')->withTrashed();
    }

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'completed_at' => 'datetime', 'meta' => 'array'];
    }
}
