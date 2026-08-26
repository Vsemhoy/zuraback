<?php

namespace App\Models;

use App\Models\Concerns\HasEntityLinks;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['scope_id', 'project_id', 'parent_id', 'created_by', 'assignee_id', 'approved_by', 'responsibility_area_id', 'title', 'description', 'result', 'status', 'priority', 'due_at', 'completed_at', 'approved_at', 'tracked_seconds', 'is_pinned', 'sort_order', 'meta', 'counts_for_compensation'])]
class Task extends DomainModel
{
    use HasEntityLinks, SoftDeletes;

    public function scope(): BelongsTo
    {
        return $this->belongsTo(Scope::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function responsibilityArea(): BelongsTo
    {
        return $this->belongsTo(ResponsibilityArea::class);
    }

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'completed_at' => 'datetime', 'approved_at' => 'datetime', 'is_pinned' => 'boolean', 'counts_for_compensation' => 'boolean', 'meta' => 'array'];
    }
}
