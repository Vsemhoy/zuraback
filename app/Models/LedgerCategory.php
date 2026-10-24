<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['scope_id', 'project_id', 'title', 'kind', 'color', 'sort_order', 'is_archived', 'revision'])]
class LedgerCategory extends DomainModel
{
    protected $attributes = ['kind' => 'both', 'color' => '#7c6a46', 'sort_order' => 0, 'is_archived' => false, 'revision' => 1];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_archived' => 'boolean', 'revision' => 'integer'];
    }
}
