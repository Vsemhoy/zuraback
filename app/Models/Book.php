<?php

namespace App\Models;

use App\Models\Concerns\HasEntityLinks;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['scope_id', 'space_id', 'project_id', 'created_by', 'title', 'slug', 'description', 'structure_mode', 'version_depth', 'visibility', 'comments_enabled', 'cover_color', 'cover_svg_url', 'cover_svg_text', 'export_settings', 'sort_order', 'is_archived', 'meta'])]
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

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function pages(): HasMany
    {
        return $this->hasMany(BookPage::class);
    }

    public function starredBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'book_stars')->withTimestamps();
    }

    protected function casts(): array
    {
        return ['export_settings' => 'array', 'is_archived' => 'boolean', 'comments_enabled' => 'boolean', 'meta' => 'array'];
    }
}
