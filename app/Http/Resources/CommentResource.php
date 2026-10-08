<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'parent_id' => $this->parent_id, 'content' => $this->content,
            'kind' => $this->kind, 'is_answered' => $this->is_answered,
            'created_by' => $this->whenLoaded('creator'), 'created_at' => $this->created_at, 'updated_at' => $this->updated_at,
        ];
    }
}
