<?php

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;

class DarklogKpiRequest extends WorkspaceRequest
{
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'ulid'],
            'kpi_id' => ['required', 'ulid', Rule::exists('kpis', 'id')->where('scope_id', $this->route('scope')->id)->whereNull('deleted_at')],
            'decision' => ['required', 'in:pending,included,excluded'],
            'reason' => ['required_unless:decision,pending', 'nullable', 'string', 'max:5000'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
        ];
    }
}
