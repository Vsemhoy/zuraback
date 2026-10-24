<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['scope_id', 'account_id', 'target_account_id', 'category_id', 'exploitation_entry_id', 'cost_type', 'created_by', 'updated_by', 'creator_name', 'kind', 'amount_minor', 'currency', 'occurred_on', 'status', 'title', 'note_md', 'is_pinned', 'idempotency_key', 'request_hash', 'revision'])]
class LedgerTransaction extends DomainModel
{
    use SoftDeletes;

    public const COST_TYPES = ['labor', 'parts', 'consumables', 'delivery', 'service', 'other', 'combined'];

    protected $attributes = ['status' => 'planned', 'is_pinned' => false, 'revision' => 1];

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    public function targetAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'target_account_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(LedgerCategory::class);
    }

    public function exploitationEntry(): BelongsTo
    {
        return $this->belongsTo(ExploitationEntry::class)->withTrashed();
    }

    protected function casts(): array
    {
        return ['amount_minor' => 'string', 'occurred_on' => 'date:Y-m-d', 'is_pinned' => 'boolean', 'revision' => 'integer'];
    }
}
