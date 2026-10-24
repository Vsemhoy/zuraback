<?php

namespace App\Http\Requests\Api;

class UpdateFactRequest extends WorkspaceRequest
{
    public function rules(): array
    {
        return [
            'visibility' => ['sometimes', 'in:private,scope,project'],
            'project_id' => ['sometimes', 'nullable', 'ulid'],
            'allow_member_editing' => ['sometimes', 'boolean'],
            'search_keywords.*' => ['string', 'max:120'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'revision' => ['required', 'integer', 'min:1'],

            'label' => ['sometimes', 'string', 'max:160'], 'value' => ['sometimes', 'required', 'string', 'max:200000'],
            'format' => ['sometimes', 'in:text,markdown,code,command,number,svg'],
            'language' => ['sometimes', 'nullable', 'string', 'max:16'], 'unit' => ['sometimes', 'nullable', 'string', 'max:32'],
            'context' => ['sometimes', 'nullable', 'string'], 'search_keywords' => ['sometimes', 'nullable', 'array', 'max:50'],
            'kind' => ['sometimes', 'string', 'max:32'], 'display_mode' => ['sometimes', 'in:plain,large,mono,article,snippet,terminal,metric,preview,qr,masked'],
            'is_sensitive' => ['sometimes', 'boolean'], 'is_expert' => ['sometimes', 'boolean'], 'is_pinned' => ['sometimes', 'boolean'],
            'valid_from' => ['sometimes', 'nullable', 'date'], 'valid_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:valid_from'],
        ];
    }
}
