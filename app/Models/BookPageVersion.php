<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['page_id', 'created_by', 'version_number', 'snapshot'])]
class BookPageVersion extends DomainModel
{
    public function page(): BelongsTo
    {
        return $this->belongsTo(BookPage::class, 'page_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }
}
