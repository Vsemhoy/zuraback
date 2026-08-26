<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
class StoreBookBlockGroupRequest extends WorkspaceRequest
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
            'type' => ['required', 'in:markdown,excalidraw,svg,table,code,callout,checklist,divider,embed'],
            'role' => ['sometimes', 'string', 'max:32'],
            'visibility' => ['sometimes', 'in:private,scope,public'],
            'sort_order' => ['sometimes', 'integer'],
            'title' => ['nullable', 'string'],
            'content' => ['nullable', 'string'],
            'payload' => ['nullable', 'array'],
            'search_text' => ['nullable', 'string'],
        ];
    }
}
