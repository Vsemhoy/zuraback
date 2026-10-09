<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['scope_id', 'name', 'description', 'color'])]
class Department extends DomainModel
{
    public const COLORS = ['#c9f1d5', '#d4e8fc', '#e5dcfa', '#f9dce5', '#fce4cd', '#f7efc6', '#cdeee9', '#e3e8ef'];

    protected $attributes = ['color' => '#c9f1d5'];

    public function members(): HasMany
    {
        return $this->hasMany(ScopeMember::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
