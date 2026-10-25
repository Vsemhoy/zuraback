<?php

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;

class SaveContentStructureRequest extends WorkspaceRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'required', 'string', 'max:96'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'color' => ['sometimes', 'nullable', 'regex:/^#[a-fA-F0-9]{6}([a-fA-F0-9]{2})?$/'],
            'parent_id' => $this->route('kind') === 'categories' ? ['prohibited'] : ['sometimes', 'nullable', 'ulid'],
            'visibility' => $this->route('kind') === 'categories' ? ['prohibited'] : ['sometimes', Rule::in(['private', 'scope', 'public'])],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'is_archived' => ['sometimes', 'boolean'],
            'revision' => [$this->isMethod('POST') ? 'prohibited' : 'required', 'integer', 'min:1'],
            'scope_id' => ['prohibited'], 'created_by' => ['prohibited'],
        ];
    }
}
