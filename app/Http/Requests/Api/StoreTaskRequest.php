<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;

class StoreTaskRequest extends WorkspaceRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'project_id' => ['nullable', 'ulid'],
            'parent_id' => ['nullable', 'ulid'],
            'assignee_id' => ['nullable', 'ulid'],
            'customer_id' => ['nullable', 'ulid'],
            'is_agent_delegatable' => ['sometimes', 'boolean'],
            'delegated_agent_id' => ['nullable', 'ulid'],
            'responsibility_area_id' => ['nullable', 'ulid'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'result' => ['nullable', 'string'],
            'status' => ['sometimes', 'in:scheduled,todo,in_progress,blocked,review,done,cancelled'],
            'priority' => ['sometimes', 'integer', 'between:1,5'],
            'due_at' => ['nullable', 'date'],
            'counts_for_compensation' => ['sometimes', 'boolean'],
        ];
    }
}
