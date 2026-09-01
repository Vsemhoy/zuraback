<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;

class StoreBookRequest extends WorkspaceRequest
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
            'space_id' => ['nullable', 'ulid'],
            'project_id' => ['nullable', 'ulid'],
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'alpha_dash', 'max:160'],
            'description' => ['nullable', 'string'],
            'visibility' => ['sometimes', 'in:private,scope,public'],
            'cover_color' => ['nullable', 'string', 'max:24'],
            'cover_svg_url' => ['nullable', 'url'],
            'cover_svg_text' => ['nullable', 'string'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
