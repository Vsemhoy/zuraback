<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
class StoreEventRequest extends WorkspaceRequest
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
            'type_id' => ['nullable', 'ulid'],
            'section_id' => ['nullable', 'ulid'],
            'parent_id' => ['nullable', 'ulid'],
            'title' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'format' => ['sometimes', 'in:markdown,text,code'],
            'language' => ['nullable', 'string', 'max:16'],
            'status' => ['sometimes', 'in:draft,published,archived'],
            'location' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'occurred_at' => ['nullable', 'date'],
            'is_all_day' => ['sometimes', 'boolean'],
        ];
    }
}
