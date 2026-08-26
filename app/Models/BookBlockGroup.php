<?php

namespace App\Models;

use App\Models\Concerns\HasEntityLinks;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['page_id', 'created_by', 'master_block_id', 'type', 'role', 'visibility', 'is_hidden_by_default', 'sort_order', 'meta'])]
class BookBlockGroup extends DomainModel
{
    use HasEntityLinks, SoftDeletes;

    public function page(): BelongsTo
    {
        return $this->belongsTo(BookPage::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function masterBlock(): BelongsTo
    {
        return $this->belongsTo(BookBlock::class, 'master_block_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(BookBlock::class, 'group_id');
    }

    protected function casts(): array
    {
        return ['is_hidden_by_default' => 'boolean', 'meta' => 'array'];
    }
}
