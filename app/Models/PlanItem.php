<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['scope_id', 'project_id', 'created_by', 'assignee_id', 'title', 'description', 'resources', 'expected_result', 'impact', 'actual_result', 'month', 'starts_on', 'ends_on', 'estimated_minutes', 'priority', 'completed_at', 'completed_by'])]
class PlanItem extends DomainModel
{
    use SoftDeletes;

    protected $attributes = ['priority' => 2];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id')->withTrashed();
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by')->withTrashed();
    }

    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'plan_item_task');
    }

    protected function casts(): array
    {
        return ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'completed_at' => 'datetime', 'estimated_minutes' => 'integer', 'priority' => 'integer'];
    }
}
