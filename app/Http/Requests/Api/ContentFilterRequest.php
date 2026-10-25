<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;

class ContentFilterRequest extends WorkspaceRequest
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
            'project_id' => ['sometimes', 'nullable', 'regex:/^(none|[0-7][0-9A-HJKMNP-TV-Z]{25})$/i'],
            'section_id' => ['sometimes', 'nullable', 'regex:/^(none|[0-7][0-9A-HJKMNP-TV-Z]{25})$/i'],
            'category_id' => ['sometimes', 'nullable', 'regex:/^(none|[0-7][0-9A-HJKMNP-TV-Z]{25})$/i'],
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
