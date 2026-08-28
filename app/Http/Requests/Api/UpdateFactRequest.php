<?php

namespace App\Http\Requests\Api;

class UpdateFactRequest extends WorkspaceRequest
{
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'string', 'max:160'], 'value' => ['sometimes', 'string'],
            'format' => ['sometimes', 'in:text,markdown,code,command,number,svg'],
            'language' => ['sometimes', 'nullable', 'string', 'max:16'], 'unit' => ['sometimes', 'nullable', 'string', 'max:32'],
            'context' => ['sometimes', 'nullable', 'string'], 'search_keywords' => ['sometimes', 'nullable', 'array'],
            'kind' => ['sometimes', 'string', 'max:32'], 'display_mode' => ['sometimes', 'string', 'max:24'],
            'is_sensitive' => ['sometimes', 'boolean'], 'is_expert' => ['sometimes', 'boolean'], 'is_pinned' => ['sometimes', 'boolean'],
            'valid_from' => ['sometimes', 'nullable', 'date'], 'valid_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:valid_from'],
            'sort_order' => ['sometimes', 'integer'],
        ];
    }
}
