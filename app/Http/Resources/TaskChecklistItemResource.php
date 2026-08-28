<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskChecklistItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'task_id' => $this->task_id,
            'title' => $this->title,
            'assignee_id' => $this->assignee_id,
            'assignee' => $this->whenLoaded('assignee'),
            'due_at' => $this->due_at,
            'completed_at' => $this->completed_at,
            'completed_by_id' => $this->resource->getAttribute('completed_by'),
            'completed_by' => $this->whenLoaded('completedBy'),
            'sort_order' => $this->sort_order,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
