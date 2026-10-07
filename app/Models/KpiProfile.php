<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['scope_id', 'user_id', 'profile_key', 'effective_month', 'targets', 'items', 'created_by'])]
class KpiProfile extends DomainModel
{
    protected function casts(): array
    {
        return ['targets' => 'array', 'items' => 'array'];
    }
}
