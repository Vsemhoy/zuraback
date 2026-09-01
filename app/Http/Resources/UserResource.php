<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $actingAs = null;

        if ($request->hasSession() && $request->user()?->is($this->resource)) {
            $actorId = $request->session()->get('contractor.actor_id');
            $actor = $actorId ? User::query()->find($actorId) : null;
            $actingAs = $actor ? ['id' => $actor->id, 'name' => $actor->name, 'type' => $actor->type] : null;
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'type' => $this->type,
            'status' => $this->status,
            'acting_as' => $actingAs,
            'acting_scope_id' => $actingAs ? $request->session()->get('contractor.scope_id') : null,
        ];
    }
}
