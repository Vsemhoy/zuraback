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
            'project_id' => ['nullable', 'ulid'],
            'requester_id' => ['nullable', 'ulid'],
            'section_id' => ['nullable', 'ulid'],
            'parent_id' => ['nullable', 'ulid'],
            'title' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'format' => ['sometimes', 'in:markdown,text,code'],
            'language' => ['nullable', 'string', 'max:16'],
            'status' => ['sometimes', 'in:draft,published,archived'],
            'importance' => ['sometimes', 'in:undefined,unimportant,read,important,critical,incident'],
            'visibility' => ['sometimes', 'in:private,scope,public'],
            'location' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'occurred_at' => ['nullable', 'date'],
            'is_all_day' => ['sometimes', 'boolean'],
            'is_pinned' => ['sometimes', 'boolean'],
            'is_locked' => ['sometimes', 'boolean'],
            'comments_enabled' => ['sometimes', 'nullable', 'boolean'],
            'is_blurred' => ['sometimes', 'boolean'],
            'diagram' => ['sometimes', 'nullable', 'array'],
            'attachments' => ['sometimes', 'nullable', 'array', 'max:100'],
            'attachments.*.url' => ['required', 'url', 'max:2048'],
            'attachments.*.label' => ['nullable', 'string', 'max:255'],
            'photos' => ['sometimes', 'nullable', 'array', 'max:100'],
            'photos.*.url' => ['required', 'url', 'max:2048'],
            'photos.*.label' => ['nullable', 'string', 'max:255'],
        ];
    }
}
