<?php

namespace App\Http\Requests\Api;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContractorRequest extends FormRequest
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
        $contractor = $this->route('contractor');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'position' => ['sometimes', 'nullable', 'string', 'max:255'],
            'preferred_language' => ['sometimes', Rule::in(User::LANGUAGES)],
            'type' => ['sometimes', Rule::in(User::TYPES)],
            'status' => ['sometimes', Rule::in(User::STATUSES)],
            'is_executor' => ['sometimes', 'boolean'],
            'username' => ['sometimes', 'nullable', 'string', 'max:255', 'alpha_dash', Rule::unique('users', 'username')->ignore($contractor)],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($contractor)],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            'profile' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
