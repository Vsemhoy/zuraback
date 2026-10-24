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
            'visibility' => ['sometimes', 'in:private,scope,project'],
            'project_id' => ['sometimes', 'nullable', 'ulid'],
            'allow_member_editing' => ['sometimes', 'boolean'],
            'search_keywords.*' => ['string', 'max:120'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000000'],

            'label' => ['required', 'string', 'max:160'],
            'value' => ['required', 'string', 'max:200000'],
            'format' => ['sometimes', 'in:text,markdown,code,command,number,svg'],
            'language' => ['nullable', 'string', 'max:16'],
            'unit' => ['nullable', 'string', 'max:32'],
            'context' => ['nullable', 'string'],
            'search_keywords' => ['nullable', 'array', 'max:50'],
            'kind' => ['sometimes', 'string', 'max:32'],
            'display_mode' => ['sometimes', 'in:plain,large,mono,article,snippet,terminal,metric,preview,qr,masked'],
            'is_sensitive' => ['sometimes', 'boolean'],
            'is_expert' => ['sometimes', 'boolean'],
            'is_pinned' => ['sometimes', 'boolean'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
        ];
    }
}
