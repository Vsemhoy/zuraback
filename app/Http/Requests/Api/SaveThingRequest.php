<?php

namespace App\Http\Requests\Api;

use App\Models\Thing;
use App\Models\ThingType;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveThingRequest extends WorkspaceRequest
{
    public function authorize(): bool
    {
        $thing = $this->route('thing');
        if ($thing) {
            abort_unless($thing->scope_id === $this->route('scope')->id, 404);
        }

        return true;
    }

    public function rules(): array
    {
        $scope = $this->route('scope');
        $thing = $this->route('thing');
        $required = $thing ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'required', 'string', 'max:255'],
            'thing_type_id' => [$required, 'required', 'ulid', Rule::exists('thing_types', 'id')->where(fn ($query) => $query->whereNull('scope_id')->orWhere('scope_id', $scope->id))],
            'department_id' => ['sometimes', 'nullable', 'ulid', Rule::exists('departments', 'id')->where('scope_id', $scope->id)],
            'registration_basis' => [$required, 'required', 'string', 'max:10000'],
            'manufacturer' => ['sometimes', 'nullable', 'string', 'max:255'],
            'model' => ['sometimes', 'nullable', 'string', 'max:255'],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'inventory_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'owner_kind' => ['sometimes', 'required', Rule::in(['organization', 'employee', 'external'])],
            'owner_id' => ['sometimes', 'nullable', 'ulid'],
            'owner_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'purchased_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'purchase_cost' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'supplier_id' => ['sometimes', 'nullable', 'ulid', Rule::exists('thing_suppliers', 'id')->where('scope_id', $scope->id)],
            'container_kind' => ['prohibited'],
            'supplier' => ['sometimes', 'nullable', 'string', 'max:255'],
            'condition' => ['sometimes', 'required', Rule::in(Thing::CONDITIONS)],
            'suitability' => ['sometimes', 'required', Rule::in(Thing::SUITABILITY)],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'characteristics' => ['sometimes', 'nullable', 'array', 'max:50'],
            'wms_product_id' => ['sometimes', 'nullable', 'string', 'max:128'],
            'wms_inventory_unit' => ['sometimes', 'nullable', 'string', 'max:128', Rule::unique('things')->where('scope_id', $scope->id)->ignore($thing?->id)],
            'revision' => [$thing ? 'required' : 'prohibited', 'integer', 'min:1'],
            'scope_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'lifecycle' => ['prohibited'],
            'assignee_id' => ['prohibited'],
            'parent_id' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $scope = $this->route('scope');
            $thing = $this->route('thing');
            $kind = $this->input('owner_kind', $thing?->owner_kind ?? 'organization');
            $ownerId = $this->input('owner_id', $thing?->owner_id);
            if ($kind === 'employee' && ! ($thing && $thing->owner_kind === 'employee' && $thing->owner_id === $ownerId) && (! $ownerId || ($ownerId !== $scope->owner_id && ! $scope->members()->where('user_id', $ownerId)->where('is_active', true)->exists()))) {
                $validator->errors()->add('owner_id', 'Выберите собственника из участников организации.');
            }
            if ($kind !== 'employee' && $ownerId !== null) {
                $validator->errors()->add('owner_id', 'Для этого вида собственности сотрудник не указывается.');
            }
            if ($kind === 'external' && ! $this->input('owner_name', $thing?->owner_name)) {
                $validator->errors()->add('owner_name', 'Укажите внешнего собственника.');
            }
            $type = ThingType::find($this->input('thing_type_id', $thing?->thing_type_id));
            $schema = collect($type?->schema ?? [])->keyBy('key');
            $values = $this->input('characteristics', $thing?->characteristics ?? []) ?? [];
            foreach ($values as $key => $value) {
                $field = $schema->get($key);
                $valid = $field && match ($field['type']) {
                    'number' => is_int($value) || is_float($value),
                    'boolean' => is_bool($value),
                    default => is_string($value) && mb_strlen($value) <= 1000,
                };
                if (! $valid) {
                    $validator->errors()->add('characteristics.'.$key, 'Характеристика не соответствует схеме выбранного типа.');
                }
            }
        }];
    }

    public function messages(): array
    {
        return [
            'registration_basis.required' => 'Укажите основание внесения оборудования.',
            'wms_inventory_unit.unique' => 'Этот экземпляр WMS уже связан с оборудованием организации.',
            'revision.required' => 'Обновите карточку перед сохранением.',
        ];
    }
}
