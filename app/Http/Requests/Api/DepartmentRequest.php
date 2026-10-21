<?php

namespace App\Http\Requests\Api;

use App\Models\Department;
use App\Rules\SvgIcon;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DepartmentRequest extends WorkspaceRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->exists('code') && is_string($this->input('code'))) {
            $code = Str::upper(trim($this->input('code')));
            $this->merge(['code' => $code === '' ? null : $code]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $scopeId = $this->route('scope')->id;
        $department = $this->route('department');

        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('departments')->where('scope_id', $scopeId)->ignore($department?->id)],
            'code' => ['sometimes', 'nullable', 'string', 'size:3', 'regex:/^[\p{L}\p{N}]{3}$/u', Rule::unique('departments')->where('scope_id', $scopeId)->ignore($department?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'color' => ['sometimes', 'required', Rule::in(Department::COLORS)],
            'icon_svg' => ['sometimes', 'nullable', 'string', 'max:32768', new SvgIcon],
            'user_ids' => ['sometimes', 'array'],
            'user_ids.*' => ['ulid', 'distinct'],
        ];
    }
}
