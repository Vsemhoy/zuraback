<?php

namespace App\Http\Requests\Api;

use App\Models\User;
use App\Services\ContractorAccessService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContractorRequest extends FormRequest
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
        $scopeId = $this->route('scope')->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::in(User::TYPES)],
            'status' => ['sometimes', Rule::in(User::STATUSES)],
            'is_executor' => ['sometimes', 'boolean'],
            'username' => ['nullable', 'string', 'max:255', 'alpha_dash', Rule::unique('users', 'username')],
            'email' => ['nullable', 'email', 'max:255', Rule::requiredIf($this->input('type') === 'real'), Rule::unique('users', 'email')],
            'password' => ['nullable', 'string', 'min:8', Rule::requiredIf($this->input('type') === 'real')],
            'role' => ['sometimes', Rule::in(['admin', 'member', 'observer'])],
            'project_access_mode' => ['sometimes', Rule::in(['all', 'restricted', 'none'])],
            'book_access_mode' => ['sometimes', Rule::in(['all', 'projects', 'none'])],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'project_ids' => ['sometimes', 'array'],
            'project_ids.*' => ['ulid', 'distinct', Rule::exists('projects', 'id')->where('scope_id', $scopeId)],
            'permissions' => ['sometimes', 'array:allow,deny'],
            'permissions.allow' => ['sometimes', 'array'],
            'permissions.allow.*' => ['string', 'distinct', Rule::in(ContractorAccessService::ABILITIES)],
            'permissions.deny' => ['sometimes', 'array'],
            'permissions.deny.*' => ['string', 'distinct', Rule::in(ContractorAccessService::ABILITIES)],
            'can_act_as' => ['sometimes', 'boolean'],
        ];
    }
}
