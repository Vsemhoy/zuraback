<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['scope_id', 'project_id', 'area_id', 'created_by', 'code', 'type', 'importance', 'criticality', 'visibility'])]
class LoreEntry extends DomainModel
{
    use SoftDeletes;

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function area(): BelongsTo { return $this->belongsTo(LoreArea::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function revisions(): HasMany { return $this->hasMany(LoreRevision::class); }
    public function tags(): BelongsToMany { return $this->belongsToMany(LoreTag::class, 'lore_entry_tag'); }
    public function starredBy(): BelongsToMany { return $this->belongsToMany(User::class, 'lore_stars')->withTimestamps(); }
}
