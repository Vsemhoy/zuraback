<?php

namespace App\Models;

use Database\Factories\ProjectMemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_id', 'user_id', 'assigned_by', 'permissions', 'is_active'])]
class ProjectMember extends DomainModel
{
    /** @use HasFactory<ProjectMemberFactory> */
    use HasFactory;

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    protected function casts(): array
    {
        return ['permissions' => 'array', 'is_active' => 'boolean'];
    }
}
