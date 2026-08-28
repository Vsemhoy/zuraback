<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'action' => $this->action, 'actor' => $this->whenLoaded('actor'),
            'subject_type' => $this->subject_type, 'subject_id' => $this->subject_id,
            'before' => $this->before, 'after' => $this->after, 'context' => $this->context,
            'created_at' => $this->created_at,
        ];
    }
}
