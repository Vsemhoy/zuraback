<?php

namespace App\Http\Requests\Api;

class StoreTaskRelationRequest extends WorkspaceRequest
{
    public function rules(): array
    {
        return [
            'task_key' => ['required', 'string', 'max:32'],
            'relation' => ['required', 'in:blocks,blocked_by,related,duplicate'],
        ];
    }
}
