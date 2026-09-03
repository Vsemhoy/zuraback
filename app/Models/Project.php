<?php

namespace App\Models;

use App\Models\Concerns\HasEntityLinks;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['scope_id', 'created_by', 'title', 'key', 'next_task_number', 'description', 'result', 'status', 'priority', 'color', 'visibility', 'show_in_tasker', 'show_in_eventor', 'event_comments_enabled', 'started_on', 'due_on', 'completed_at', 'is_pinned', 'sort_order', 'meta'])]
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

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function books(): HasMany
    {
        return $this->hasMany(Book::class);
    }

    public function loreEntries(): HasMany
    {
        return $this->hasMany(LoreEntry::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    protected function casts(): array
    {
        return ['started_on' => 'date', 'due_on' => 'date', 'completed_at' => 'datetime', 'is_pinned' => 'boolean', 'show_in_tasker' => 'boolean', 'show_in_eventor' => 'boolean', 'event_comments_enabled' => 'boolean', 'meta' => 'array'];
    }
}
