<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['starts_at', 'ends_at', 'note'])]
class DarklogPeriod extends DomainModel
{
    public function darklog(): BelongsTo
    {
        return $this->belongsTo(Darklog::class);
    }

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }
}
