<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['group_id', 'created_by', 'version_number', 'title', 'content', 'payload', 'search_text', 'status', 'published_at'])]
class BookBlock extends DomainModel
{
    use SoftDeletes;

    public function group(): BelongsTo
    {
        return $this->belongsTo(BookBlockGroup::class, 'group_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function casts(): array
    {
        return ['payload' => 'array', 'published_at' => 'datetime'];
    }
}
