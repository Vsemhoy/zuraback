<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;

class ImportTaskerRequest extends WorkspaceRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'source' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9._-]*$/'],
            'dry_run' => ['sometimes', 'boolean'],
            'projects' => ['sometimes', 'array', 'max:100'],
            'projects.*.external_id' => ['required', 'ulid', 'distinct'],
            'projects.*.title' => ['required', 'string', 'max:255'],
            'projects.*.key' => ['required', 'string', 'min:2', 'max:10', 'regex:/^[A-Z][A-Z0-9]*$/', 'distinct'],
            'projects.*.description' => ['nullable', 'string'],
            'projects.*.result' => ['nullable', 'string'],
            'projects.*.status' => ['required', 'in:planning,active,on_hold,completed,archived'],
            'projects.*.priority' => ['required', 'integer', 'between:1,5'],
            'projects.*.color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'projects.*.started_on' => ['nullable', 'date'],
            'projects.*.due_on' => ['nullable', 'date'],
            'projects.*.completed_at' => ['nullable', 'date'],
            'projects.*.is_pinned' => ['sometimes', 'boolean'],
            'projects.*.sort_order' => ['required', 'integer'],
            'projects.*.created_at' => ['required', 'date'],
            'projects.*.updated_at' => ['required', 'date'],
            'tasks' => ['sometimes', 'array', 'max:1000'],
            'tasks.*.external_id' => ['required', 'ulid', 'distinct'],
            'tasks.*.project_external_id' => ['required', 'ulid'],
            'tasks.*.parent_external_id' => ['nullable', 'ulid'],
            'tasks.*.assignee_id' => ['nullable', 'ulid'],
            'tasks.*.title' => ['required', 'string', 'max:255'],
            'tasks.*.description' => ['nullable', 'string'],
            'tasks.*.result' => ['nullable', 'string'],
            'tasks.*.status' => ['required', 'in:scheduled,todo,in_progress,blocked,review,done,cancelled'],
            'tasks.*.priority' => ['required', 'integer', 'between:1,5'],
            'tasks.*.due_at' => ['nullable', 'date'],
            'tasks.*.completed_at' => ['nullable', 'date'],
            'tasks.*.tracked_seconds' => ['required', 'integer', 'min:0'],
            'tasks.*.is_pinned' => ['sometimes', 'boolean'],
            'tasks.*.sort_order' => ['required', 'integer'],
            'tasks.*.legacy_spans' => ['sometimes', 'array', 'max:500'],
            'tasks.*.created_at' => ['required', 'date'],
            'tasks.*.updated_at' => ['required', 'date'],
            'checklist_items' => ['sometimes', 'array', 'max:5000'],
            'checklist_items.*.external_id' => ['required', 'ulid', 'distinct'],
            'checklist_items.*.task_external_id' => ['required', 'ulid'],
            'checklist_items.*.title' => ['required', 'string', 'max:255'],
            'checklist_items.*.is_completed' => ['required', 'boolean'],
            'checklist_items.*.completed_at' => ['nullable', 'date'],
            'checklist_items.*.sort_order' => ['required', 'integer'],
            'checklist_items.*.created_at' => ['required', 'date'],
            'checklist_items.*.updated_at' => ['required', 'date'],
            'comments' => ['sometimes', 'array', 'max:5000'],
            'comments.*.external_id' => ['required', 'ulid', 'distinct'],
            'comments.*.task_external_id' => ['required', 'ulid'],
            'comments.*.kind' => ['required', 'in:note,report'],
            'comments.*.content' => ['required', 'string'],
            'comments.*.created_at' => ['required', 'date'],
            'comments.*.updated_at' => ['required', 'date'],
            'activities' => ['sometimes', 'array', 'max:5000'],
            'activities.*.external_id' => ['required', 'ulid', 'distinct'],
            'activities.*.task_external_id' => ['required', 'ulid'],
            'activities.*.before' => ['required', 'array'],
            'activities.*.after' => ['required', 'array'],
            'activities.*.created_at' => ['required', 'date'],
        ];
    }
}
