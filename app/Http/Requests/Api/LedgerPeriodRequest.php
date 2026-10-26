<?php

namespace App\Http\Requests\Api;

use Carbon\CarbonImmutable;
use Illuminate\Validation\Validator;

class LedgerPeriodRequest extends WorkspaceRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'start' => ['required', 'date_format:Y-m', 'after_or_equal:1900-01', 'before_or_equal:2100-12'],
            'end' => ['required', 'date_format:Y-m', 'after_or_equal:start', 'before_or_equal:'.($this->route()->getActionMethod() === 'flow' ? '2100-12' : now()->format('Y-m'))],
            'project_id' => ['nullable', 'ulid'], 'account_id' => ['nullable', 'ulid', 'prohibits:account_ids'],
            'account_ids' => ['sometimes', 'array', 'max:100'],
            'account_ids.*' => ['required', 'ulid', 'distinct'],
            'archived' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $start = CarbonImmutable::createFromFormat('!Y-m', $this->input('start'));
            $end = CarbonImmutable::createFromFormat('!Y-m', $this->input('end'));
            $limit = $this->route()->getActionMethod() === 'flow' ? 3 : 24;
            if ($start->diffInMonths($end) >= $limit) {
                $validator->errors()->add('end', "Выберите период не более {$limit} месяцев.");
            }
        }];
    }
}
