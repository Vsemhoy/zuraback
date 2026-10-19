<?php

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;

class UpdateLightbookRequest extends WorkspaceRequest
{
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'project_id' => ['sometimes', 'nullable', 'ulid', Rule::exists('projects', 'id')->where('scope_id', $this->route('scope')->id)->whereNull('deleted_at')],
            'visibility' => ['sometimes', Rule::in(['private', 'scope', 'public'])],
            'comments_enabled' => ['sometimes', 'boolean'],
            'version_depth' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
