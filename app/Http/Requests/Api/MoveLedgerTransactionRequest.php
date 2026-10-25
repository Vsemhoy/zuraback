<?php

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;

class MoveLedgerTransactionRequest extends WorkspaceRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'revision' => ['required', 'integer', 'min:1'],
            'account_id' => ['required', 'ulid'],
            'occurred_on' => ['required', 'date_format:Y-m-d'],
            'side' => ['required', Rule::in(['source', 'target'])],
        ];
    }
}
