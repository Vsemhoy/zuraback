<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'kpi_id', 'decision', 'reason', 'effective_from', 'effective_until', 'created_by', 'reviewed_by', 'reviewed_at'])]
class DarklogKpiLink extends DomainModel
{
    protected $attributes = ['decision' => 'pending'];

    public function darklog(): BelongsTo
    {
        return $this->belongsTo(Darklog::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function kpi(): BelongsTo
    {
        return $this->belongsTo(Kpi::class)->withTrashed();
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }

    protected function casts(): array
    {
        return ['effective_from' => 'datetime', 'effective_until' => 'datetime', 'reviewed_at' => 'datetime'];
    }
}
