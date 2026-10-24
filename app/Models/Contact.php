<?php

namespace App\Models;

use App\Models\Concerns\HasEntityLinks;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['scope_id', 'created_by', 'user_id', 'project_id', 'visibility', 'allow_member_editing', 'kind', 'name', 'nickname', 'company', 'role', 'phone', 'email', 'birthday_on', 'met_at', 'met_context', 'last_contact_at', 'note_md', 'is_pinned', 'is_archived', 'sort_order', 'revision'])]
class Contact extends DomainModel
{
    use HasEntityLinks, SoftDeletes;

    protected $attributes = ['visibility' => 'private', 'revision' => 1, 'kind' => 'person', 'allow_member_editing' => false, 'is_pinned' => false, 'is_archived' => false, 'sort_order' => 0];

    public function scope(): BelongsTo
    {
        return $this->belongsTo(Scope::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(ContactNote::class);
    }

    protected function casts(): array
    {
        return ['birthday_on' => 'date:Y-m-d', 'met_at' => 'date:Y-m-d', 'last_contact_at' => 'datetime', 'is_pinned' => 'boolean', 'is_archived' => 'boolean', 'allow_member_editing' => 'boolean', 'revision' => 'integer'];
    }
}
