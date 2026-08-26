<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['responsibility_area_id', 'user_id', 'assigned_by', 'active_from', 'active_until'])]
class ResponsibilityAreaAssignment extends DomainModel
{
    public function area(): BelongsTo
    {
        return $this->belongsTo(ResponsibilityArea::class, 'responsibility_area_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    protected function casts(): array
    {
        return ['active_from' => 'date', 'active_until' => 'date'];
    }
}
