<?php

namespace App\Http\Requests\Api;

class StoreTaskBlockerRequest extends WorkspaceRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:5000'],
            'resolution_required' => ['required', 'string', 'max:5000'],
            'responsible_user_id' => ['nullable', 'ulid'],
            'responsible_text' => ['nullable', 'string', 'max:255', 'required_without:responsible_user_id'],
            'next_review_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
