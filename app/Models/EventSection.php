<?php

namespace App\Models;

use App\Models\Concerns\HasEntityLinks;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['scope_id', 'created_by', 'name', 'slug', 'description', 'color', 'background_color', 'icon', 'visibility', 'sort_order', 'is_default', 'is_archived', 'decor', 'seo'])]
class EventSection extends DomainModel
{
    use HasEntityLinks;

    public function scope(): BelongsTo
    {
        return $this->belongsTo(Scope::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'section_id');
    }

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_archived' => 'boolean', 'decor' => 'array', 'seo' => 'array'];
    }
}
