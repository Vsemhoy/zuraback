<?php

namespace App\Http\Requests\Api;

use App\Models\ThingAssignment;
use Illuminate\Validation\Rule;

class EquipOperationRequest extends WorkspaceRequest
{
    public function authorize(): bool
    {
        return $this->route('thing')->scope_id === $this->route('scope')->id;
    }

    public function rules(): array
    {
        return [
            'operation' => ['required', Rule::in(['assign', 'return', 'container', 'attach', 'detach', 'disband'])],
            'revision' => ['required', 'integer', 'min:1'],
            'expected_revisions' => ['required_if:operation,assign,return', 'array', 'max:1000'],
            'expected_revisions.*' => ['integer', 'min:1'],
            'user_id' => ['required_if:operation,assign', 'nullable', 'ulid'],
            'responsibility' => ['required_if:operation,assign', Rule::in(ThingAssignment::RESPONSIBILITIES)],
            'container_kind' => ['required_if:operation,container', Rule::in(['none', 'physical', 'logical'])],
            'child_id' => ['required_if:operation,attach', 'ulid'],
            'child_revision' => ['required_if:operation,attach', 'integer', 'min:1'],
            'relation_id' => ['required_if:operation,detach', 'ulid'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
