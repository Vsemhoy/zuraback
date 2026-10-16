<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['scope_id', 'project_id', 'created_by', 'title', 'description', 'visibility', 'xml', 'preview_svg', 'revision'])]
class Diagram extends DomainModel
{
    use SoftDeletes;

    public const EMPTY_XML = '<mxfile><diagram id="page-1" name="Page-1"><mxGraphModel><root><mxCell id="0"/><mxCell id="1" parent="0"/></root></mxGraphModel></diagram></mxfile>';

    protected $attributes = ['visibility' => 'private', 'revision' => 1, 'xml' => self::EMPTY_XML];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(DiagramRevision::class)->orderByDesc('number');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(DiagramAttachment::class);
    }

    protected function casts(): array
    {
        return ['revision' => 'integer'];
    }
}
