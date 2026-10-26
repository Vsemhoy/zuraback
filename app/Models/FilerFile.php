<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['scope_id', 'created_by', 'uploaded_by', 'name', 'description', 'category', 'container', 'visibility', 'disk', 'path', 'mime', 'size', 'sha256'])]
class FilerFile extends DomainModel
{
    public const CATEGORIES = ['thing', 'documentation', 'general', 'task', 'event', 'book', 'project', 'user', 'darklog', 'ledger_transaction'];

    protected $attributes = ['visibility' => 'private', 'container' => 'files'];

    public function attachments(): HasMany
    {
        return $this->hasMany(FilerAttachment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function casts(): array
    {
        return ['size' => 'integer', 'preview_requested_at' => 'datetime'];
    }
}
