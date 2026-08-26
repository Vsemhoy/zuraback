<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['scope_id', 'name', 'slug', 'color', 'is_system', 'is_archived'])]
class Tag extends DomainModel
{
    public function scope(): BelongsTo
    {
        return $this->belongsTo(Scope::class);
    }

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'is_archived' => 'boolean'];
    }
}
