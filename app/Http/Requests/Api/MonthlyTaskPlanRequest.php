<?php

namespace App\Http\Requests\Api;

class MonthlyTaskPlanRequest extends WorkspaceRequest
{
    public function rules(): array
    {
        return [
            'month' => ['required', 'date_format:Y-m', 'regex:/^(20[0-9]{2})-(0[1-9]|1[0-2])$/'],
            'planned_on' => ['required', 'date_format:Y-m-d'],
            'task_id' => ['required', 'ulid'],
            'assignee_id' => ['nullable', 'ulid'],
            'expected_result' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
