<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScopeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);
        unset($data['is_appearance_admin']);

        return [...$data, 'can_manage_appearance' => $this->owner_id === $request->user()?->id || (bool) $this->is_appearance_admin];
    }
}
