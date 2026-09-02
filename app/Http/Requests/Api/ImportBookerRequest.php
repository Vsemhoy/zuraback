<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;

class ImportBookerRequest extends WorkspaceRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'source' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9._-]*$/'],
            'dry_run' => ['sometimes', 'boolean'],
            'book.external_id' => ['required', 'ulid'],
            'book.title' => ['required', 'string', 'max:255'],
            'book.slug' => ['nullable', 'alpha_dash', 'max:160'],
            'book.description' => ['nullable', 'string'],
            'book.structure_mode' => ['required', 'in:tree,flat'],
            'book.visibility' => ['required', 'in:private,scope,public'],
            'book.cover_color' => ['nullable', 'string', 'max:24'],
            'book.cover_svg_url' => ['nullable', 'url'],
            'book.cover_svg_text' => ['nullable', 'string'],
            'book.export_settings' => ['nullable', 'array'],
            'book.sort_order' => ['required', 'integer'],
            'book.is_archived' => ['required', 'boolean'],
            'book.meta' => ['nullable', 'array'],
            'book.created_at' => ['required', 'date'],
            'book.updated_at' => ['required', 'date'],
            'pages' => ['present', 'array', 'max:1000'],
            'pages.*.external_id' => ['required', 'ulid', 'distinct'],
            'pages.*.parent_external_id' => ['nullable', 'ulid'],
            'pages.*.title' => ['required', 'string', 'max:255'],
            'pages.*.slug' => ['nullable', 'alpha_dash', 'max:160'],
            'pages.*.visibility' => ['required', 'in:private,scope,public'],
            'pages.*.sort_order' => ['required', 'integer'],
            'pages.*.is_archived' => ['required', 'boolean'],
            'pages.*.meta' => ['nullable', 'array'],
            'pages.*.created_at' => ['required', 'date'],
            'pages.*.updated_at' => ['required', 'date'],
            'groups' => ['present', 'array', 'max:5000'],
            'groups.*.external_id' => ['required', 'ulid', 'distinct'],
            'groups.*.page_external_id' => ['required', 'ulid'],
            'groups.*.master_block_external_id' => ['required', 'ulid'],
            'groups.*.type' => ['required', 'in:markdown,excalidraw,svg,table,code,callout,checklist,divider,embed'],
            'groups.*.role' => ['required', 'string', 'max:32'],
            'groups.*.visibility' => ['required', 'in:private,scope,public'],
            'groups.*.is_hidden_by_default' => ['required', 'boolean'],
            'groups.*.sort_order' => ['required', 'integer'],
            'groups.*.meta' => ['nullable', 'array'],
            'groups.*.created_at' => ['required', 'date'],
            'groups.*.updated_at' => ['required', 'date'],
            'blocks' => ['present', 'array', 'max:10000'],
            'blocks.*.external_id' => ['required', 'ulid', 'distinct'],
            'blocks.*.group_external_id' => ['required', 'ulid'],
            'blocks.*.version_number' => ['required', 'integer', 'min:1'],
            'blocks.*.title' => ['nullable', 'string'],
            'blocks.*.content' => ['nullable', 'string'],
            'blocks.*.payload' => ['nullable', 'array'],
            'blocks.*.status' => ['required', 'in:draft,published,archived'],
            'blocks.*.published_at' => ['nullable', 'date'],
            'blocks.*.created_at' => ['required', 'date'],
            'blocks.*.updated_at' => ['required', 'date'],
        ];
    }
}
