<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['scope_id', 'name', 'normalized_name'])]
class ThingSupplier extends DomainModel
{
    public static function normalize(string $name): string
    {
        return preg_replace('/\s+/u', ' ', trim($name));
    }
}
