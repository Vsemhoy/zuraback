<?php

namespace App\Http\Requests\Api;

use App\Services\LedgerMoney;
use Illuminate\Validation\Rule;

class SaveLedgerAccountRequest extends WorkspaceRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'ulid'],
            'revision' => [$this->isMethod('PATCH') ? 'required' : 'prohibited', 'integer', 'min:1'],
            'title' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:160'],
            'type' => ['sometimes', Rule::in(['bank', 'cash', 'wallet'])],
            'currency' => [$this->isMethod('POST') ? 'required' : 'prohibited', Rule::in(LedgerMoney::CURRENCIES)],
            'opening_balance' => ['sometimes', 'regex:/^-?[0-9]{1,10}(?:\\.[0-9]{1,2})?$/D'],
            'description' => ['nullable', 'string', 'max:10000'],
            'color' => ['sometimes', 'regex:/^#[a-fA-F0-9]{6}$/D'],
            'sort_order' => ['sometimes', 'integer', 'between:0,1000000'],
            'is_archived' => ['sometimes', 'boolean'],
            'scope_id' => ['prohibited'], 'opening_minor' => ['prohibited'], 'created_by' => ['prohibited'], 'creator_name' => ['prohibited'],
        ];
    }
}
