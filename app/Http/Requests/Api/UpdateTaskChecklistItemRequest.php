<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;

class UpdateTaskChecklistItemRequest extends WorkspaceRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'assignee_id' => ['sometimes', 'nullable', 'ulid'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'sort_order' => ['sometimes', 'integer'],
            'is_completed' => ['sometimes', 'boolean'],
        ];
    }
}
