<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LightbookResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'scope_id' => $this->scope_id,
            'project_id' => $this->project_id,
            'section_id' => $this->section_id,
            'category_id' => $this->category_id,
            'created_by' => $this->created_by,
            'title' => $this->title,
            'description' => $this->description,
            'visibility' => $this->visibility,
            'comments_enabled' => (bool) $this->comments_enabled,
            'version_depth' => $this->version_depth,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'creator' => $this->whenLoaded('creator'),
            'project' => $this->whenLoaded('project'),
            'page_id' => $this->whenLoaded('page', fn () => $this->page?->id),
            'page' => $this->whenLoaded('page', fn () => $this->page ? (new BookPageResource($this->page))->resolve($request) : null),
        ];
    }
}
