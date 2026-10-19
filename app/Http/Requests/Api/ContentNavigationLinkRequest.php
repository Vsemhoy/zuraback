<?php

namespace App\Http\Requests\Api;

use App\Services\ContentNavigationService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ContentNavigationLinkRequest extends WorkspaceRequest
{
    private const REGION_OVERSCAN = 0.05;
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
            'target_type' => ['required', Rule::in(array_keys(ContentNavigationService::TYPES))],
            'target_id' => ['required', 'ulid'],
            'label' => ['sometimes', 'nullable', 'string', 'max:160'],
            'region' => ['sometimes', 'nullable', 'array:page_id,x,y,width,height', 'size:5'],
            'region.page_id' => ['required_with:region', 'string', 'max:255'],
            'region.x' => ['required_with:region', 'numeric', 'between:-0.05,1.05'],
            'region.y' => ['required_with:region', 'numeric', 'between:-0.05,1.05'],
            'region.width' => ['required_with:region', 'numeric', 'gt:0', 'max:1.1'],
            'region.height' => ['required_with:region', 'numeric', 'gt:0', 'max:1.1'],
            'source_revision' => ['required_with:region', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->filled('region')) {
                return;
            }
            $region = $this->input('region');
            $minimum = -self::REGION_OVERSCAN;
            $maximum = 1 + self::REGION_OVERSCAN;
            if ($this->route('type') !== 'diagram' || $region['x'] < $minimum - 0.000001 || $region['y'] < $minimum - 0.000001 || $region['x'] + $region['width'] > $maximum + 0.000001 || $region['y'] + $region['height'] > $maximum + 0.000001) {
                $validator->errors()->add('region', 'Рамка должна находиться внутри страницы схемы с полем 5%.');
            }
        }];
    }
}
