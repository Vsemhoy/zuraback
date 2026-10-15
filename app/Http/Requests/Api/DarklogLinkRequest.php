<?php

namespace App\Http\Requests\Api;

use App\Models\DarklogLink;
use App\Services\DarklogLinkService;
use Illuminate\Validation\Rule;

class DarklogLinkRequest extends WorkspaceRequest
{
    public function rules(): array
    {
        return [
            'target_type' => ['required', Rule::in(array_keys(DarklogLinkService::TARGETS))],
            'target_id' => ['required', 'ulid'],
            'relation' => ['required', Rule::in(DarklogLink::RELATIONS)],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
