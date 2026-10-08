<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['scope_id', 'commentable_type', 'commentable_id', 'parent_id', 'created_by', 'content', 'kind', 'is_answered'])]
class Comment extends DomainModel
{
    use SoftDeletes;

    protected $attributes = ['kind' => 'comment', 'is_answered' => false];

    protected function casts(): array
    {
        return ['is_answered' => 'boolean'];
    }

    public function scope(): BelongsTo
    {
        return $this->belongsTo(Scope::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}
