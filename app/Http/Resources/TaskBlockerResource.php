<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskBlockerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'task_id' => $this->task_id,
            'reason' => $this->reason,
            'resolution_required' => $this->resolution_required,
            'responsible_user_id' => $this->responsible_user_id,
            'responsible_user' => $this->whenLoaded('responsibleUser'),
            'responsible_text' => $this->responsible_text,
            'previous_status' => $this->previous_status,
            'blocked_by' => $this->whenLoaded('blockedBy'),
            'blocked_at' => $this->blocked_at,
            'resolved_by' => $this->whenLoaded('resolvedBy'),
            'resolved_at' => $this->resolved_at,
            'resolution_note' => $this->resolution_note,
            'next_review_at' => $this->next_review_at,
            'is_active' => $this->resolved_at === null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
