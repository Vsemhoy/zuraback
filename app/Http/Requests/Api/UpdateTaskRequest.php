<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;

class UpdateTaskRequest extends WorkspaceRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'project_id' => ['sometimes', 'nullable', 'ulid'],
            'parent_id' => ['sometimes', 'nullable', 'ulid'],
            'assignee_id' => ['sometimes', 'nullable', 'ulid'],
            'responsibility_area_id' => ['sometimes', 'nullable', 'ulid'],
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'result' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'in:scheduled,todo,in_progress,blocked,review,done,cancelled'],
            'priority' => ['sometimes', 'integer', 'between:1,5'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'counts_for_compensation' => ['sometimes', 'boolean'],
        ];
    }
}
