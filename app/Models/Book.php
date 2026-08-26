<?php

namespace App\Models;

use App\Models\Concerns\HasEntityLinks;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['scope_id', 'space_id', 'created_by', 'title', 'slug', 'description', 'structure_mode', 'visibility', 'cover_color', 'cover_svg_url', 'cover_svg_text', 'export_settings', 'sort_order', 'is_archived', 'meta'])]
class Book extends DomainModel
{
    use HasEntityLinks, SoftDeletes;

    public function scope(): BelongsTo
    {
        return $this->belongsTo(Scope::class);
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(BookSpace::class, 'space_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function pages(): HasMany
    {
        return $this->hasMany(BookPage::class);
    }

    protected function casts(): array
    {
        return ['export_settings' => 'array', 'is_archived' => 'boolean', 'meta' => 'array'];
    }
}
