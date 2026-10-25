<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['scope_id', 'created_by', 'name', 'description', 'color', 'sort_order', 'is_archived', 'revision'])]
class ContentCategory extends DomainModel
{
    protected $attributes = ['sort_order' => 0, 'is_archived' => false, 'revision' => 1];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_archived' => 'boolean', 'revision' => 'integer'];
    }
}
