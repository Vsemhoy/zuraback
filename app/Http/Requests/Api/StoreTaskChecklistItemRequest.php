<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;

class StoreTaskChecklistItemRequest extends WorkspaceRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'assignee_id' => ['nullable', 'ulid'],
            'due_at' => ['nullable', 'date'],
            'sort_order' => ['sometimes', 'integer'],
        ];
    }
}
