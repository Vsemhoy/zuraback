<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['scope_id', 'created_by', 'title', 'slug', 'visibility', 'sort_order', 'is_archived', 'meta'])]
class BookSpace extends DomainModel
{
    use SoftDeletes;

    public function scope(): BelongsTo
    {
        return $this->belongsTo(Scope::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function books(): HasMany
    {
        return $this->hasMany(Book::class, 'space_id');
    }

    protected function casts(): array
    {
        return ['is_archived' => 'boolean', 'meta' => 'array'];
    }
}
