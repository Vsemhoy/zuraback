<?php

namespace App\Http\Resources;

use App\Models\BookPage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookCommentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $page = $this->relationLoaded('commentable') ? $this->commentable : null;

        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'content' => $this->content,
            'created_by' => $this->whenLoaded('creator'),
            'page' => $page instanceof BookPage ? [
                'id' => $page->id,
                'title' => $page->title,
                'book_id' => $page->book_id,
                'book' => $page->relationLoaded('book') ? $page->book?->only(['id', 'title']) : null,
            ] : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
