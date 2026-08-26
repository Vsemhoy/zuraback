<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['scope_id', 'actor_id', 'subject_type', 'subject_id', 'action', 'before', 'after', 'context', 'ip_address', 'user_agent'])]
class ActivityLog extends DomainModel
{
    public const UPDATED_AT = null;

    public function scope(): BelongsTo
    {
        return $this->belongsTo(Scope::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'context' => 'array'];
    }
}
