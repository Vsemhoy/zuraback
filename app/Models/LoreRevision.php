<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
#[Fillable(['lore_entry_id', 'created_by', 'version', 'title', 'content', 'reason', 'status', 'effective_from', 'effective_until'])]
class LoreRevision extends DomainModel
{
    public function entry(): BelongsTo { return $this->belongsTo(LoreEntry::class, 'lore_entry_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    protected function casts(): array { return ['effective_from' => 'datetime', 'effective_until' => 'datetime']; }
}
