<?php

namespace App\Http\Requests\Api;

class UpdateEventRequest extends WorkspaceRequest
{
    public function rules(): array
    {
        return [
            'type_id' => ['sometimes', 'nullable', 'ulid'], 'project_id' => ['sometimes', 'nullable', 'ulid'],
            'requester_id' => ['sometimes', 'nullable', 'ulid'], 'section_id' => ['sometimes', 'nullable', 'ulid'],
            'parent_id' => ['sometimes', 'nullable', 'ulid'], 'title' => ['sometimes', 'string', 'max:255'],
            'content' => ['sometimes', 'nullable', 'string'], 'format' => ['sometimes', 'in:markdown,text,code'],
            'language' => ['sometimes', 'nullable', 'string', 'max:16'], 'code_language' => ['sometimes', 'nullable', 'string', 'max:32'],
            'status' => ['sometimes', 'in:draft,published,archived'], 'relation_type' => ['sometimes', 'nullable', 'string', 'max:24'],
            'importance' => ['sometimes', 'in:undefined,unimportant,read,important,critical,incident'],
            'visibility' => ['sometimes', 'in:private,scope,public'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'], 'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_at'], 'occurred_at' => ['sometimes', 'nullable', 'date'],
            'is_all_day' => ['sometimes', 'boolean'], 'is_pinned' => ['sometimes', 'boolean'],
            'is_locked' => ['sometimes', 'boolean'], 'is_expert' => ['sometimes', 'boolean'], 'sort_order' => ['sometimes', 'integer'],
            'comments_enabled' => ['sometimes', 'nullable', 'boolean'], 'is_blurred' => ['sometimes', 'boolean'],
            'diagram' => ['sometimes', 'nullable', 'array'],
            'attachments' => ['sometimes', 'nullable', 'array', 'max:100'], 'attachments.*.url' => ['required', 'url', 'max:2048'],
            'attachments.*.label' => ['nullable', 'string', 'max:255'], 'photos' => ['sometimes', 'nullable', 'array', 'max:100'],
            'photos.*.url' => ['required', 'url', 'max:2048'], 'photos.*.label' => ['nullable', 'string', 'max:255'],
        ];
    }
}
