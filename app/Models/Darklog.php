<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['scope_id', 'project_id', 'department_id', 'created_by', 'responsible_id', 'number', 'title', 'description', 'impact', 'workaround', 'resolution', 'category', 'kind', 'origin', 'severity', 'status', 'decision_reason', 'expected_end_at'])]
class Darklog extends DomainModel
{
    use SoftDeletes;

    public const STATUSES = ['new', 'investigating', 'working', 'waiting', 'monitoring', 'closed', 'accepted', 'unconfirmed'];

    public const KINDS = ['incident', 'constraint', 'external_factor', 'regression'];

    public const ORIGINS = ['internal', 'external', 'systemic', 'unknown'];

    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    protected $attributes = ['status' => 'new', 'kind' => 'incident', 'origin' => 'unknown', 'severity' => 'medium'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id')->withTrashed();
    }

    public function periods(): HasMany
    {
        return $this->hasMany(DarklogPeriod::class)->orderBy('starts_at');
    }

    public function links(): HasMany
    {
        return $this->hasMany(DarklogLink::class)->orderBy('created_at');
    }

    public function kpiLinks(): HasMany
    {
        return $this->hasMany(DarklogKpiLink::class);
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    protected function casts(): array
    {
        return ['expected_end_at' => 'datetime', 'number' => 'integer'];
    }
}
