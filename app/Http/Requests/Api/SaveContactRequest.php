<?php

namespace App\Http\Requests\Api;

class SaveContactRequest extends WorkspaceRequest
{
    public function rules(): array
    {
        $update = $this->isMethod('PATCH');
        $rules = [
            'name' => [$update ? 'sometimes' : 'required', 'required', 'string', 'max:255'],
            'kind' => ['sometimes', 'in:person,organization'],
            'visibility' => ['sometimes', 'in:private,scope,project'],
            'project_id' => ['sometimes', 'nullable', 'ulid'],
            'user_id' => ['sometimes', 'nullable', 'ulid'],
            'allow_member_editing' => ['sometimes', 'boolean'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'birthday_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'met_at' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'last_contact_at' => ['sometimes', 'nullable', 'date'],
            'note_md' => ['sometimes', 'nullable', 'string', 'max:200000'],
            'met_context' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'is_pinned' => ['sometimes', 'boolean'], 'is_archived' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'revision' => [$update ? 'required' : 'sometimes', 'integer', 'min:1'],
        ];
        foreach (['nickname', 'company', 'role', 'phone'] as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'string', 'max:255'];
        }

        return $rules;
    }
}
