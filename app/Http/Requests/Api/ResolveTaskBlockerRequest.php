<?php

namespace App\Http\Requests\Api;

class ResolveTaskBlockerRequest extends WorkspaceRequest
{
    public function rules(): array
    {
        return ['resolution_note' => ['required', 'string', 'max:5000']];
    }
}
