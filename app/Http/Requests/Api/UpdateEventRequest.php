<?php

namespace App\Http\Requests\Api;

class UpdateEventRequest extends WorkspaceRequest
{
    public function rules(): array
    {
        return [
            'type_id' => ['sometimes', 'nullable', 'ulid'], 'section_id' => ['sometimes', 'nullable', 'ulid'],
            'parent_id' => ['sometimes', 'nullable', 'ulid'], 'title' => ['sometimes', 'string', 'max:255'],
            'content' => ['sometimes', 'nullable', 'string'], 'format' => ['sometimes', 'in:markdown,text,code'],
            'language' => ['sometimes', 'nullable', 'string', 'max:16'], 'code_language' => ['sometimes', 'nullable', 'string', 'max:32'],
            'status' => ['sometimes', 'in:draft,published,archived'], 'relation_type' => ['sometimes', 'nullable', 'string', 'max:24'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'], 'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_at'], 'occurred_at' => ['sometimes', 'nullable', 'date'],
            'is_all_day' => ['sometimes', 'boolean'], 'is_pinned' => ['sometimes', 'boolean'],
            'is_locked' => ['sometimes', 'boolean'], 'is_expert' => ['sometimes', 'boolean'], 'sort_order' => ['sometimes', 'integer'],
        ];
    }
}
