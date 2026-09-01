<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;

class StoreEntityLinkRequest extends WorkspaceRequest
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
            'source_type' => ['required', 'in:book,book_block_group,book_page,event,event_section,fact,kpi,project,task'],
            'source_id' => ['required', 'ulid'],
            'target_type' => ['required', 'in:book,book_block_group,book_page,event,event_section,fact,kpi,project,task'],
            'target_id' => ['required', 'ulid', 'different:source_id'],
            'relation' => ['sometimes', 'string', 'max:32'],
            'note' => ['nullable', 'string'],
            'meta' => ['nullable', 'array'],
        ];
    }
}
