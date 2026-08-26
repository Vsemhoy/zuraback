<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['compensation_period_id', 'responsibility_area_id', 'area_name', 'area_kind', 'area_points', 'minimum_completed_tasks', 'completed_tasks', 'is_qualified', 'awarded_points', 'approved_by', 'approved_at', 'decision_note'])]
class CompensationAreaResult extends DomainModel
{
    public function period(): BelongsTo
    {
        return $this->belongsTo(CompensationPeriod::class, 'compensation_period_id');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(ResponsibilityArea::class, 'responsibility_area_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'compensation_result_tasks')->withPivot(['credited_user_id', 'confirmed_by', 'confirmed_at'])->withTimestamps();
    }

    protected function casts(): array
    {
        return ['is_qualified' => 'boolean', 'approved_at' => 'datetime'];
    }
}
