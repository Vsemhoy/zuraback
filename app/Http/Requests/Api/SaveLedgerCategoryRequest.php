<?php

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;

class SaveLedgerCategoryRequest extends WorkspaceRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'ulid'],
            'revision' => [$this->isMethod('PATCH') ? 'required' : 'prohibited', 'integer', 'min:1'],
            'title' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:120'],
            'parent_id' => ['nullable', 'ulid'],
            'keywords' => ['sometimes', 'array', 'max:50'],
            'keywords.*' => ['required', 'string', 'max:100', 'regex:/\S/u'],
            'kind' => ['sometimes', Rule::in(['income', 'expense', 'both'])],
            'color' => ['sometimes', 'regex:/^#[a-fA-F0-9]{6}$/D'],
            'sort_order' => ['sometimes', 'integer', 'between:0,1000000'],
            'is_archived' => ['sometimes', 'boolean'], 'scope_id' => ['prohibited'],
        ];
    }
}
