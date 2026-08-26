<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
class StoreEventTypeRequest extends WorkspaceRequest
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
            'name' => ['required', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:9'],
            'background_color' => ['nullable', 'string', 'max:9'],
            'icon' => ['nullable', 'string', 'max:64'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
