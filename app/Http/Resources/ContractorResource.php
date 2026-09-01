<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContractorResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $scopeId = $request->route('scope')?->id;
        $membership = $this->scopeMemberships->firstWhere('scope_id', $scopeId);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'position' => $this->position,
            'type' => $this->type,
            'status' => $this->status,
            'username' => $this->username,
            'email' => $this->email,
            'activated_at' => $this->activated_at,
            'profile' => $this->profile,
            'role' => $membership?->role ?? 'owner',
            'permissions' => $membership?->permissions ?? ['allow' => ['*'], 'deny' => []],
            'project_access_mode' => $membership?->project_access_mode ?? 'all',
            'book_access_mode' => $membership?->book_access_mode ?? 'none',
            'projects' => $this->projectMemberships->map(fn ($member): array => [
                'id' => $member->project->id,
                'title' => $member->project->title,
                'key' => $member->project->key,
                'color' => $member->project->color,
            ])->values(),
            'scopes' => $this->scopeMemberships->map(fn ($member): array => ['id' => $member->scope->id, 'name' => $member->scope->name, 'role' => $member->role])
                ->concat($this->ownedScopes->map(fn ($owned): array => ['id' => $owned->id, 'name' => $owned->name, 'role' => 'owner']))
                ->unique('id')->values(),
            'can_act_as' => $this->receivedDelegations->contains('is_active', true),
            'tokens' => $this->when($this->isAgent(), fn () => $this->tokens->map(fn ($token): array => [
                'id' => $token->id,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'last_used_at' => $token->last_used_at,
                'expires_at' => $token->expires_at,
            ])->values()),
        ];
    }
}
