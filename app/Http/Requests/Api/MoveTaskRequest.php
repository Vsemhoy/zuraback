<?php

namespace App\Http\Requests\Api;

class MoveTaskRequest extends WorkspaceRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', 'in:scheduled,todo,in_progress,review,done,cancelled'],
            'target_index' => ['required', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
