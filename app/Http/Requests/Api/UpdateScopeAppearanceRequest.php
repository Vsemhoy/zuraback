<?php

namespace App\Http\Requests\Api;

use App\Models\Scope;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateScopeAppearanceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $scope = $this->route('scope');

        return $scope instanceof Scope && ($scope->owner_id === $this->user()->id
            || $scope->members()->where('user_id', $this->user()->id)->where('is_active', true)->where('role', 'admin')->exists());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'header_theme' => ['required', 'array:mode,start_color,end_color,start_opacity,end_opacity,angle,blur_px'],
            'header_theme.mode' => ['required', 'in:solid,gradient'],
            'header_theme.start_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'header_theme.end_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'header_theme.start_opacity' => ['required', 'integer', 'between:0,100'],
            'header_theme.end_opacity' => ['required', 'integer', 'between:0,100'],
            'header_theme.angle' => ['required', 'integer', 'between:0,360'],
            'header_theme.blur_px' => ['sometimes', 'required', 'integer', 'between:0,40'],
        ];
    }
}
