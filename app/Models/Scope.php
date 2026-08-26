<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['owner_id', 'name', 'slug', 'color', 'icon', 'default_module', 'pin_hash', 'auto_lock_minutes', 'is_private', 'is_active', 'settings'])]
class Scope extends DomainModel
{
    use SoftDeletes;

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ScopeMember::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    protected function casts(): array
    {
        return ['is_private' => 'boolean', 'is_active' => 'boolean', 'settings' => 'array'];
    }
}
