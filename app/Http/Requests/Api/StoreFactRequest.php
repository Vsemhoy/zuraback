<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
class StoreFactRequest extends WorkspaceRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:160'],
            'value' => ['required', 'string'],
            'format' => ['sometimes', 'in:text,markdown,code,command,number,svg'],
            'language' => ['nullable', 'string', 'max:16'],
            'unit' => ['nullable', 'string', 'max:32'],
            'context' => ['nullable', 'string'],
            'search_keywords' => ['nullable', 'array'],
            'kind' => ['sometimes', 'string', 'max:32'],
            'display_mode' => ['sometimes', 'string', 'max:24'],
            'is_sensitive' => ['sometimes', 'boolean'],
            'is_expert' => ['sometimes', 'boolean'],
            'is_pinned' => ['sometimes', 'boolean'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
        ];
    }
}
