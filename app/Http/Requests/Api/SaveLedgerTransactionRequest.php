<?php

namespace App\Http\Requests\Api;

use App\Models\LedgerTransaction;
use Illuminate\Validation\Rule;

class SaveLedgerTransactionRequest extends WorkspaceRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'account_id' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'ulid'],
            'target_account_id' => [$this->isMethod('PATCH') ? 'prohibited' : 'nullable', 'required_if:kind,transfer', 'ulid'],
            'kind' => [$this->isMethod('POST') ? 'required' : 'prohibited', Rule::in(LedgerTransaction::KINDS)],
            'revision' => [$this->isMethod('PATCH') ? 'required' : 'prohibited', 'integer', 'min:1'],
            'idempotency_key' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'uuid'],
            'title' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:255'],
            'amount' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'regex:/^[0-9]{1,10}(?:\\.[0-9]{1,2})?$/D'],
            'occurred_on' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'date_format:Y-m-d'],
            'status' => ['sometimes', Rule::in(['planned', 'posted', 'void'])],
            'category_id' => ['nullable', 'ulid'], 'note_md' => ['nullable', 'string', 'max:1000000'],
            'is_pinned' => ['sometimes', 'boolean'],
            'cost_type' => ['sometimes', Rule::in(LedgerTransaction::COST_TYPES)],
            'scope_id' => ['prohibited'], 'created_by' => ['prohibited'], 'creator_name' => ['prohibited'],
            'updated_by' => ['prohibited'], 'currency' => ['prohibited'], 'amount_minor' => ['prohibited'],
            'exploitation_entry_id' => ['prohibited'],
        ];
    }
}
