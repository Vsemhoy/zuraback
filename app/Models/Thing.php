<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['scope_id', 'department_id', 'thing_type_id', 'created_by', 'name', 'manufacturer', 'model', 'serial_number', 'inventory_number', 'owner_kind', 'owner_id', 'owner_name', 'purchased_on', 'purchase_cost', 'supplier', 'supplier_id', 'registration_basis', 'condition', 'suitability', 'characteristics', 'description', 'wms_product_id', 'wms_inventory_unit', 'revision'])]
class Thing extends DomainModel
{
    public const CONDITIONS = ['working', 'maintenance', 'broken'];

    public const SUITABILITY = ['suitable', 'limited', 'unsuitable', 'unassessed'];

    protected $attributes = ['owner_kind' => 'organization', 'condition' => 'working', 'suitability' => 'unassessed', 'lifecycle' => 'active', 'revision' => 1, 'container_kind' => 'none'];

    public function type(): BelongsTo
    {
        return $this->belongsTo(ThingType::class, 'thing_type_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id')->withTrashed();
    }

    public function supplierEntry(): BelongsTo
    {
        return $this->belongsTo(ThingSupplier::class, 'supplier_id');
    }

    public function currentAssignment(): HasOne
    {
        return $this->hasOne(ThingAssignment::class)->where('active', 1);
    }

    protected function casts(): array
    {
        return ['characteristics' => 'array', 'purchased_on' => 'date:Y-m-d', 'purchase_cost' => 'decimal:2', 'revision' => 'integer'];
    }
}
