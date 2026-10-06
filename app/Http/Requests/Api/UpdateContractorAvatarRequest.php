<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContractorAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        $scope = $this->route('scope');
        $contractor = $this->route('contractor');
        abort_unless($scope->owner_id === $contractor->id || $scope->members()->where('user_id', $contractor->id)->where('is_active', true)->exists(), 404);

        return $this->user()->id === $scope->owner_id || $this->user()->id === $contractor->id;
    }

    public function rules(): array
    {
        return [
            'avatar' => ['present', 'nullable', 'array:preset,file_id,crop'],
            'avatar.preset' => [Rule::requiredIf(fn (): bool => $this->input('avatar') !== null && ! $this->filled('avatar.file_id')), 'nullable', Rule::in(config('avatars.presets', [])), Rule::prohibitedIf($this->filled('avatar.file_id'))],
            'avatar.file_id' => [Rule::requiredIf(fn (): bool => $this->input('avatar') !== null && ! $this->filled('avatar.preset')), 'nullable', 'ulid', Rule::prohibitedIf($this->filled('avatar.preset'))],
            'avatar.crop' => ['sometimes', 'array:x,y,zoom'],
            'avatar.crop.x' => ['required_with:avatar.crop', 'numeric', 'between:0,100'],
            'avatar.crop.y' => ['required_with:avatar.crop', 'numeric', 'between:0,100'],
            'avatar.crop.zoom' => ['required_with:avatar.crop', 'numeric', 'between:1,3'],
        ];
    }
}
