<?php

namespace App\Models;

use Database\Factories\ContractorDelegationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['scope_id', 'operator_id', 'contractor_id', 'assigned_by', 'permissions', 'is_active'])]
class ContractorDelegation extends DomainModel
{
    /** @use HasFactory<ContractorDelegationFactory> */
    use HasFactory;

    public function scope(): BelongsTo
    {
        return $this->belongsTo(Scope::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contractor_id');
    }

    protected function casts(): array
    {
        return ['permissions' => 'array', 'is_active' => 'boolean'];
    }
}
