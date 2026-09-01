<?php

namespace App\Http\Requests\Api;

class StoreKpiRequest extends WorkspaceRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'kind' => ['required', 'in:salary,bonus'],
            'points' => ['required', 'integer', 'between:0,1000'],
            'minimum_completed_tasks' => ['required', 'integer', 'between:1,1000'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer'],
        ];
    }
}
