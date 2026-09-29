<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['scope_id', 'created_by', 'month', 'person_id', 'person_name', 'timezone', 'file_path', 'snapshot', 'sha256', 'size_bytes'])]
class MonthlyReport extends DomainModel
{
    protected $hidden = ['snapshot', 'file_path'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'size_bytes' => 'integer'];
    }
}
