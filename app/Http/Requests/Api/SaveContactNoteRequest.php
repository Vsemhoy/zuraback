<?php

namespace App\Http\Requests\Api;

class SaveContactNoteRequest extends WorkspaceRequest
{
    public function rules(): array
    {
        return ['title' => ['nullable', 'string', 'max:255'], 'content' => ['required', 'string', 'max:200000'], 'occurred_at' => ['sometimes', 'date']];
    }
}
