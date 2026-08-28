<?php

namespace App\Http\Requests\Api;

class StoreTaskCommentRequest extends WorkspaceRequest
{
    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'max:20000'],
            'parent_id' => ['nullable', 'ulid'],
        ];
    }
}
