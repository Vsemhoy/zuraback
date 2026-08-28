<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;

class UpdateProjectRequest extends WorkspaceRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'result' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'in:planning,active,on_hold,completed,archived'],
            'priority' => ['sometimes', 'integer', 'between:1,5'],
            'started_on' => ['sometimes', 'nullable', 'date'],
            'due_on' => ['sometimes', 'nullable', 'date'],
            'responsibility_area_id' => ['sometimes', 'nullable', 'ulid'],
        ];
    }
}
