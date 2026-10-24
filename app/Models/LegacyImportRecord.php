<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;

#[Fillable(['scope_id', 'created_by', 'batch_id', 'source', 'source_type', 'source_id', 'target_type', 'target_id', 'source_hash', 'snapshot'])]
#[Hidden(['snapshot'])]
class LegacyImportRecord extends DomainModel
{
    protected function casts(): array
    {
        return ['snapshot' => 'encrypted:array'];
    }
}
