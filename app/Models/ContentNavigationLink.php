<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['scope_id', 'created_by', 'source_type', 'source_id', 'target_type', 'target_id', 'label', 'region', 'source_revision'])]
class ContentNavigationLink extends DomainModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['region' => 'array', 'source_revision' => 'integer'];
    }
}
