<?php

namespace App\Models\Concerns;

use App\Models\Comment;
use App\Models\EntityLink;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait HasEntityLinks
{
    public function outboundLinks(): MorphMany
    {
        return $this->morphMany(EntityLink::class, 'source');
    }

    public function inboundLinks(): MorphMany
    {
        return $this->morphMany(EntityLink::class, 'target');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable')->withTimestamps();
    }
}
