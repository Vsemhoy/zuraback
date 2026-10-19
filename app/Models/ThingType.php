<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['scope_id', 'code', 'name', 'parent_id', 'schema'])]
class ThingType extends DomainModel
{
    protected function casts(): array
    {
        return ['schema' => 'array'];
    }
}
