<?php

namespace App\Http\Requests\Api;

class MonthlyReportRequest extends WorkspaceRequest
{
    public function rules(): array
    {
        return [
            'month' => ['required', 'date_format:Y-m', 'regex:/^(20[0-9]{2})-(0[1-9]|1[0-2])$/'],
            'user_id' => ['nullable', 'ulid'],
            'timezone' => ['required', 'timezone'],
        ];
    }
}
