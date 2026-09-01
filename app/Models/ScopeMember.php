<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['scope_id', 'user_id', 'role', 'permissions', 'project_access_mode', 'is_active', 'joined_at'])]
class ScopeMember extends DomainModel
{
    public function scope(): BelongsTo
    {
        return $this->belongsTo(Scope::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['permissions' => 'array', 'is_active' => 'boolean', 'joined_at' => 'datetime'];
    }
}
