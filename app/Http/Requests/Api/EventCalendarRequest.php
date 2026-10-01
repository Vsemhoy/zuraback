<?php

namespace App\Http\Requests\Api;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Validator;

class EventCalendarRequest extends WorkspaceRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'until' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'timezone' => ['sometimes', 'required', 'timezone'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,200'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $validator->errors()->isEmpty()) {
                return;
            }
            if (CarbonImmutable::parse($this->input('from'))->diffInDays(CarbonImmutable::parse($this->input('until'))) > 93) {
                $validator->errors()->add('until', 'Выберите диапазон не больше 93 дней.');
            }
        }];
    }
}
