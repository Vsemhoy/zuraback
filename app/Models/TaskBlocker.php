<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['task_id', 'reason', 'resolution_required', 'responsible_user_id', 'responsible_text', 'previous_status', 'blocked_by', 'blocked_at', 'resolved_by', 'resolved_at', 'resolution_note', 'next_review_at'])]
class TaskBlocker extends DomainModel
{
    public function task(): BelongsTo { return $this->belongsTo(Task::class); }
    public function responsibleUser(): BelongsTo { return $this->belongsTo(User::class, 'responsible_user_id'); }
    public function blockedBy(): BelongsTo { return $this->belongsTo(User::class, 'blocked_by'); }
    public function resolvedBy(): BelongsTo { return $this->belongsTo(User::class, 'resolved_by'); }

    protected function casts(): array
    {
        return ['blocked_at' => 'datetime', 'resolved_at' => 'datetime', 'next_review_at' => 'datetime'];
    }
}
