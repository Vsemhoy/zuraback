<?php

namespace App\Models;

use App\Models\Concerns\HasEntityLinks;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['scope_id', 'created_by', 'responsibility_area_id', 'title', 'key', 'next_task_number', 'description', 'result', 'status', 'priority', 'color', 'started_on', 'due_on', 'completed_at', 'is_pinned', 'sort_order', 'meta'])]
class Project extends DomainModel
{
    use HasEntityLinks, SoftDeletes;

    protected $attributes = [
        'color' => '#2668D8',
    ];

    public function scope(): BelongsTo
    {
        return $this->belongsTo(Scope::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function responsibilityArea(): BelongsTo
    {
        return $this->belongsTo(ResponsibilityArea::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    protected function casts(): array
    {
        return ['started_on' => 'date', 'due_on' => 'date', 'completed_at' => 'datetime', 'is_pinned' => 'boolean', 'meta' => 'array'];
    }
}
