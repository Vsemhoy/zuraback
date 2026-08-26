<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['scope_id', 'name', 'description', 'color', 'background_color', 'icon', 'sort_order', 'is_default', 'is_archived'])]
class EventType extends DomainModel
{
    public function scope(): BelongsTo
    {
        return $this->belongsTo(Scope::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'type_id');
    }

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_archived' => 'boolean'];
    }
}
