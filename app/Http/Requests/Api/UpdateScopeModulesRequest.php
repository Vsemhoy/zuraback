<?php

namespace App\Http\Requests\Api;

use App\Models\Scope;
use App\Services\ScopeModuleService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateScopeModulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $scope = $this->route('scope');

        return $scope instanceof Scope && $scope->owner_id === $this->user()->id
            && ! $this->user()->isAgent() && ! $this->session()->has('contractor.actor_id');
    }

    public function rules(): array
    {
        $catalog = ScopeModuleService::catalog();
        $rules = [
            'enabled' => ['required', 'array:'.implode(',', array_keys($catalog))],
            'order' => ['required', 'array', 'list', 'size:'.count(ScopeModuleService::sortable())],
            'order.*' => ['required', 'string', 'distinct', Rule::in(ScopeModuleService::sortable())],
        ];
        foreach ($catalog as $id => $module) {
            $rules['enabled.'.$id] = ($module['required'] ?? false) ? ['required', 'accepted'] : ['required', 'boolean'];
        }

        return $rules;
    }
}
