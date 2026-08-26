<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
class StoreProjectRequest extends WorkspaceRequest
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
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'in:planning,active,on_hold,completed,archived'],
            'priority' => ['sometimes', 'integer', 'between:1,5'],
            'started_on' => ['nullable', 'date'],
            'due_on' => ['nullable', 'date', 'after_or_equal:started_on'],
            'responsibility_area_id' => ['nullable', 'ulid'],
        ];
    }
}
