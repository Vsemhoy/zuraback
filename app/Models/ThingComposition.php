<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['scope_id', 'parent_id', 'child_id', 'parent_name', 'child_name', 'kind', 'started_at', 'ended_at', 'active', 'physical_active', 'created_by', 'closed_by', 'note', 'closing_note'])]
class ThingComposition extends DomainModel
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }
}
