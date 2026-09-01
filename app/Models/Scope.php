<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['owner_id', 'name', 'slug', 'task_prefix', 'next_task_number', 'color', 'icon', 'default_module', 'pin_hash', 'auto_lock_minutes', 'is_private', 'is_active', 'settings'])]
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

    public function delegations(): HasMany
    {
        return $this->hasMany(ContractorDelegation::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function facts(): HasMany
    {
        return $this->hasMany(Fact::class);
    }

    public function books(): HasMany
    {
        return $this->hasMany(Book::class);
    }

    public function bookSpaces(): HasMany
    {
        return $this->hasMany(BookSpace::class);
    }

    public function eventTypes(): HasMany
    {
        return $this->hasMany(EventType::class);
    }

    public function eventSections(): HasMany
    {
        return $this->hasMany(EventSection::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function responsibilityAreas(): HasMany
    {
        return $this->hasMany(ResponsibilityArea::class);
    }

    public function entityLinks(): HasMany
    {
        return $this->hasMany(EntityLink::class);
    }

    protected function casts(): array
    {
        return ['is_private' => 'boolean', 'is_active' => 'boolean', 'settings' => 'array'];
    }
}
