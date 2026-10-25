<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['scope_id', 'project_id', 'created_by', 'creator_name', 'title', 'type', 'currency', 'opening_minor', 'description', 'color', 'sort_order', 'is_archived', 'revision', 'interest_rate_bps', 'interest_start_on', 'interest_end_on', 'balance_is_known', 'exclude_from_reports'])]
class LedgerAccount extends DomainModel
{
    protected $attributes = ['type' => 'bank', 'currency' => 'RUB', 'opening_minor' => 0, 'color' => '#7c6a46', 'sort_order' => 0, 'is_archived' => false, 'revision' => 1, 'balance_is_known' => true, 'exclude_from_reports' => false];

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
        return ['opening_minor' => 'string', 'sort_order' => 'integer', 'is_archived' => 'boolean', 'revision' => 'integer',
            'interest_rate_bps' => 'integer', 'interest_start_on' => 'date:Y-m-d', 'interest_end_on' => 'date:Y-m-d', 'balance_is_known' => 'boolean', 'exclude_from_reports' => 'boolean'];
    }
}
