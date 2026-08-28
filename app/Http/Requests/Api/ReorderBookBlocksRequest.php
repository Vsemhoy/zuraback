<?php

namespace App\Http\Requests\Api;

class ReorderBookBlocksRequest extends WorkspaceRequest
{
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'ulid', 'distinct'],
            'items.*.sort_order' => ['required', 'integer', 'min:1'],
        ];
    }
}
