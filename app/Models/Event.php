<?php

namespace App\Models;

use App\Models\Concerns\HasEntityLinks;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['scope_id', 'created_by', 'type_id', 'section_id', 'parent_id', 'root_id', 'title', 'content', 'format', 'language', 'code_language', 'status', 'relation_type', 'location', 'starts_at', 'ends_at', 'occurred_at', 'is_all_day', 'is_pinned', 'is_locked', 'is_expert', 'sort_order', 'meta'])]
class Event extends DomainModel
{
    use HasEntityLinks, SoftDeletes;

    public function scope(): BelongsTo
    {
        return $this->belongsTo(Scope::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(EventType::class, 'type_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(EventSection::class, 'section_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'occurred_at' => 'datetime', 'is_all_day' => 'boolean', 'is_pinned' => 'boolean', 'is_locked' => 'boolean', 'is_expert' => 'boolean', 'meta' => 'array'];
    }
}
