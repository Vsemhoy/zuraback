<?php

namespace App\Http\Requests\Api;

use App\Services\ContractorAccessService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContractorAccessRequest extends FormRequest
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
            'role' => ['required', Rule::in(['owner', 'admin', 'member', 'observer'])],
            'project_access_mode' => ['required', Rule::in(['all', 'restricted', 'none'])],
            'project_ids' => ['present', 'array'],
            'project_ids.*' => ['ulid', 'distinct', Rule::exists('projects', 'id')->where('scope_id', $scopeId)],
            'permissions' => ['required', 'array:allow,deny'],
            'permissions.allow' => ['present', 'array'],
            'permissions.allow.*' => ['string', 'distinct', Rule::in([...ContractorAccessService::ABILITIES, '*'])],
            'permissions.deny' => ['present', 'array'],
            'permissions.deny.*' => ['string', 'distinct', Rule::in([...ContractorAccessService::ABILITIES, '*'])],
            'can_act_as' => ['sometimes', 'boolean'],
        ];
    }
}
