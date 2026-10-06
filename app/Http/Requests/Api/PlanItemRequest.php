<?php

namespace App\Http\Requests\Api;

class PlanItemRequest extends WorkspaceRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'required', 'string', 'max:255'],
            'month' => [$required, 'required', 'date_format:Y-m', 'regex:/^20[0-9]{2}-(0[1-9]|1[0-2])$/'],
            'project_id' => ['sometimes', 'nullable', 'ulid'],
            'assignee_id' => ['sometimes', 'nullable', 'ulid'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'resources' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'expected_result' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'impact' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'actual_result' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'starts_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'estimated_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:525600'],
            'priority' => ['sometimes', 'integer', 'between:1,5'],
            'completed' => ['sometimes', 'boolean'],
            'task_dates' => ['sometimes', 'array', 'max:500'],
            'task_dates.*' => ['required', 'date_format:Y-m-d'],
            'task_ids' => ['sometimes', 'array', 'max:500'],
            'task_ids.*' => ['required', 'ulid', 'distinct'],
        ];
    }
}
