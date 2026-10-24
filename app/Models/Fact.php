<?php

namespace App\Models;

use App\Models\Concerns\HasEntityLinks;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['scope_id', 'created_by', 'label', 'value', 'format', 'language', 'unit', 'context', 'search_keywords', 'kind', 'display_mode', 'is_sensitive', 'is_expert', 'is_pinned', 'valid_from', 'valid_to', 'sort_order', 'project_id', 'visibility', 'allow_member_editing', 'revision'])]
class Fact extends DomainModel
{
    use HasEntityLinks, SoftDeletes;

    protected $hidden = ['value'];

    protected $attributes = ['visibility' => 'private', 'revision' => 1, 'allow_member_editing' => false];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function scope(): BelongsTo
    {
        return $this->belongsTo(Scope::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function casts(): array
    {
        return ['revision' => 'integer', 'allow_member_editing' => 'boolean', 'search_keywords' => 'array', 'is_sensitive' => 'boolean', 'is_expert' => 'boolean', 'is_pinned' => 'boolean', 'valid_from' => 'date', 'valid_to' => 'date'];
    }
}
