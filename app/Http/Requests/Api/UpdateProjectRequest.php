<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends WorkspaceRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'department_id' => ['sometimes', 'nullable', 'ulid', Rule::exists('departments', 'id')->where('scope_id', $this->route('scope')->id)],
            'department_ids' => ['sometimes', 'array'],
            'department_ids.*' => ['ulid', 'distinct', Rule::exists('departments', 'id')->where('scope_id', $this->route('scope')->id)],
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'result' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'in:planning,active,on_hold,completed,archived'],
            'priority' => ['sometimes', 'integer', 'between:1,5'],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'visibility' => ['sometimes', 'in:private,scope'],
            'include_in_reports' => ['sometimes', 'boolean'],
            'show_in_tasker' => ['sometimes', 'boolean'],
            'show_in_eventor' => ['sometimes', 'boolean'],
            'event_comments_enabled' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'started_on' => ['sometimes', 'nullable', 'date'],
            'due_on' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
