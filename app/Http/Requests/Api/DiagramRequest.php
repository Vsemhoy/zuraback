<?php

namespace App\Http\Requests\Api;

use App\Rules\DrawioXml;
use Illuminate\Validation\Rule;

class DiagramRequest extends WorkspaceRequest
{
    public function rules(): array
    {
        return [
            'title' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'visibility' => ['sometimes', Rule::in(['private', 'scope'])],
            'allow_member_editing' => ['sometimes', 'boolean'],
            'project_id' => ['sometimes', 'nullable', 'ulid', Rule::exists('projects', 'id')->where('scope_id', $this->route('scope')->id)->whereNull('deleted_at')],
            'revision' => [$this->isMethod('PATCH') ? 'required' : 'prohibited', 'integer', 'min:1'],
            'xml' => ['sometimes', 'required', 'string', 'max:5242880', new DrawioXml],
            'preview_svg' => ['sometimes', 'nullable', 'string', 'max:5242880'],
        ];
    }
}
