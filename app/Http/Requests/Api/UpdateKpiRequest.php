<?php

namespace App\Http\Requests\Api;

class UpdateKpiRequest extends WorkspaceRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'kind' => ['sometimes', 'in:salary,bonus'],
            'points' => ['sometimes', 'integer', 'between:0,1000'],
            'minimum_completed_tasks' => ['sometimes', 'integer', 'between:1,1000'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer'],
        ];
    }
}
