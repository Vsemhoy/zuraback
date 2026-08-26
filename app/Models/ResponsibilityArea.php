<?php

namespace App\Models;

use App\Models\Concerns\HasEntityLinks;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['scope_id', 'created_by', 'name', 'description', 'kind', 'points', 'minimum_completed_tasks', 'requires_approval', 'is_active', 'sort_order'])]
class ResponsibilityArea extends DomainModel
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

    public function assignments(): HasMany
    {
        return $this->hasMany(ResponsibilityAreaAssignment::class);
    }

    protected function casts(): array
    {
        return ['requires_approval' => 'boolean', 'is_active' => 'boolean'];
    }
}
