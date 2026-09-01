<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['scope_id', 'task_id', 'created_by', 'planned_on'])]
class TaskPlannerTail extends DomainModel
{
    public function scope(): BelongsTo
    {
        return $this->belongsTo(Scope::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function casts(): array
    {
        return ['planned_on' => 'date:Y-m-d'];
    }
}
