<?php

namespace App\Http\Requests\Api;

use App\Services\ContractorAccessService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAgentTokenRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', 'distinct', Rule::in(ContractorAccessService::ABILITIES)],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ];
    }
}
