<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
class StoreScopeRequest extends WorkspaceRequest
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
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'alpha_dash', 'max:120', 'unique:scopes,slug'],
            'color' => ['nullable', 'string', 'max:9'],
            'icon' => ['nullable', 'string', 'max:64'],
            'is_private' => ['sometimes', 'boolean'],
        ];
    }
}
