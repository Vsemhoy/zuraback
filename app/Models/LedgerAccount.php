<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['scope_id', 'project_id', 'created_by', 'creator_name', 'title', 'type', 'currency', 'opening_minor', 'description', 'color', 'sort_order', 'is_archived', 'revision'])]
class LedgerAccount extends DomainModel
{
    protected $attributes = ['type' => 'bank', 'currency' => 'RUB', 'opening_minor' => 0, 'color' => '#7c6a46', 'sort_order' => 0, 'is_archived' => false, 'revision' => 1];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(LedgerTransaction::class, 'account_id');
    }

    public function receivedTransfers(): HasMany
    {
        return $this->hasMany(LedgerTransaction::class, 'target_account_id');
    }

    protected function casts(): array
    {
        return ['opening_minor' => 'string', 'sort_order' => 'integer', 'is_archived' => 'boolean', 'revision' => 'integer'];
    }
}
