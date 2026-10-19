<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['scope_id', 'thing_id', 'user_id', 'user_name', 'responsibility', 'started_at', 'ended_at', 'active', 'created_by', 'closed_by', 'note', 'closing_note', 'batch_root_id'])]
class ThingAssignment extends DomainModel
{
    public const RESPONSIBILITIES = ['use', 'custody', 'temporary'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
