<?php

namespace App\Http\Requests\Api;

use App\Models\Darklog;
use Illuminate\Validation\Rule;

class DarklogRequest extends WorkspaceRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $scopeId = $this->route('scope')->id;
        $project = Rule::exists('projects', 'id')->where('scope_id', $scopeId);
        if ($this->isMethod('POST') || $this->input('project_id') !== $this->route('darklog')?->project_id) {
            $project->whereNull('deleted_at');
        }

        return [
            'title' => [$required, 'required', 'string', 'max:255'],
            'project_id' => ['sometimes', 'nullable', 'ulid', $project],
            'department_id' => ['sometimes', 'nullable', 'ulid', Rule::exists('departments', 'id')->where('scope_id', $scopeId)],
            'responsible_id' => ['sometimes', 'nullable', 'ulid'],
            'kind' => ['sometimes', Rule::in(Darklog::KINDS)],
            'origin' => ['sometimes', Rule::in(Darklog::ORIGINS)],
            'severity' => ['sometimes', Rule::in(Darklog::SEVERITIES)],
            'status' => ['sometimes', Rule::in(Darklog::STATUSES)],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:100000'],
            'impact' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'workaround' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'resolution' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'decision_reason' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'expected_end_at' => ['sometimes', 'nullable', 'date'],
            'periods' => [$required, 'array', 'max:1000'],
            'periods.*.id' => ['sometimes', 'ulid', 'distinct'],
            'periods.*.starts_at' => ['required', 'date', 'before_or_equal:now'],
            'periods.*.ends_at' => ['nullable', 'date', 'before_or_equal:now'],
            'periods.*.note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
