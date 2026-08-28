<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['scope_id', 'task_id', 'task_key'])]
class TaskKeyAlias extends DomainModel
{
    public function scope(): BelongsTo { return $this->belongsTo(Scope::class); }
    public function task(): BelongsTo { return $this->belongsTo(Task::class); }
}
