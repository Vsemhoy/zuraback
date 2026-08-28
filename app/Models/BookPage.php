<?php

namespace App\Models;

use App\Models\Concerns\HasEntityLinks;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['book_id', 'parent_id', 'created_by', 'editing_by', 'editing_started_at', 'title', 'slug', 'visibility', 'sort_order', 'is_archived', 'meta'])]
class BookPage extends DomainModel
{
    use HasEntityLinks, SoftDeletes;

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(BookBlockGroup::class, 'page_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editing_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(BookPageVersion::class, 'page_id');
    }

    protected function casts(): array
    {
        return ['is_archived' => 'boolean', 'editing_started_at' => 'datetime', 'meta' => 'array'];
    }
}
