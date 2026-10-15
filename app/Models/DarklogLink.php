<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['target_type', 'target_id', 'relation', 'note', 'created_by'])]
class DarklogLink extends DomainModel
{
    public const RELATIONS = ['possible_cause', 'confirmed_cause', 'affected', 'remediation', 'context', 'duplicate'];

    public function darklog(): BelongsTo
    {
        return $this->belongsTo(Darklog::class);
    }
}
