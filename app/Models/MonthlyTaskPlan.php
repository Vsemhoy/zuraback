<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['scope_id', 'task_id', 'month', 'assignee_id', 'expected_result', 'created_by'])]
class MonthlyTaskPlan extends DomainModel
{
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
