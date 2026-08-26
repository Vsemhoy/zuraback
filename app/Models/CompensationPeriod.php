<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['scope_id', 'user_id', 'month', 'salary_amount', 'earned_points', 'payable_percent', 'bonus_amount', 'status', 'closed_by', 'closed_at'])]
class CompensationPeriod extends DomainModel
{
    public function scope(): BelongsTo
    {
        return $this->belongsTo(Scope::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function areaResults(): HasMany
    {
        return $this->hasMany(CompensationAreaResult::class);
    }

    protected function casts(): array
    {
        return ['month' => 'date', 'salary_amount' => 'decimal:2', 'bonus_amount' => 'decimal:2', 'closed_at' => 'datetime'];
    }
}
