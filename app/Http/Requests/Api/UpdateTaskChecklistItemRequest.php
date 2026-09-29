<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class UpdateTaskChecklistItemRequest extends WorkspaceRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'assignee_id' => ['sometimes', 'nullable', 'ulid',
                ...($this->input('assignee_id') === $this->route('item')?->assignee_id
                    ? [] : [Rule::exists('users', 'id')->whereNull('deleted_at')]),
            ],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'sort_order' => ['sometimes', 'integer'],
            'is_completed' => ['sometimes', 'boolean'],
        ];
    }
}
