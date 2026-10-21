<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SaveThingRequest;
use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\FilerFile;
use App\Models\Scope;
use App\Models\Thing;
use App\Models\ThingAssignment;
use App\Models\ThingSupplier;
use App\Models\ThingType;
use App\Models\User;
use App\Services\ContractorAccessService;
use App\Services\ContractorContext;
use App\Services\EquipAccessService;
use App\Services\FilerAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EquipController extends Controller
{
    private const SORTS = ['id', 'name', 'type', 'department', 'assignee', 'owner', 'supplier', 'manufacturer', 'model', 'inventory_number', 'serial_number', 'condition', 'suitability', 'container_kind', 'purchase_cost', 'purchased_on', 'registration_basis', 'description', 'wms_product_id', 'wms_inventory_unit', 'created_at', 'updated_at'];

    public function __construct(
        private readonly EquipAccessService $equip,
        private readonly ContractorAccessService $access,
        private readonly ContractorContext $context,
        private readonly FilerAccessService $files,
    ) {}

    public function options(Request $request, Scope $scope): JsonResponse
    {
        $actor = $this->context->actor($request);
        $all = $this->equip->allDepartments($actor, $scope);
        $departmentId = $this->access->membership($actor, $scope)?->department_id;
        $departments = Department::where('scope_id', $scope->id);
        if (! $all) {
            $departments->whereKey($departmentId ?? '');
        }
        $permissions = ['task_view' => $this->access->allows($actor, $scope, 'task.view'), 'task_update' => $this->access->allows($actor, $scope, 'task.update')];
        foreach (['create', 'update', 'assign', 'compose', 'state', 'ownership', 'types', 'access_all'] as $ability) {
            $permissions[$ability] = $this->access->allows($actor, $scope, 'equip.'.$ability);
        }

        return response()->json(['data' => [
            'types' => ThingType::where(fn (Builder $query) => $query->whereNull('scope_id')->orWhere('scope_id', $scope->id))->orderBy('name')->get(),
            'departments' => $departments->orderBy('name')->get(['id', 'name', 'color', 'code']),
            'people' => User::where(fn (Builder $query) => $query->whereKey($scope->owner_id)->orWhereIn('id', $scope->members()->where('is_active', true)->select('user_id')))->orderBy('name')->get(['id', 'name']),
            'assignees' => User::whereIn('type', ['real', 'virtual'])->where('status', 'active')->where('is_active', true)->where(fn (Builder $query) => $query->whereKey($scope->owner_id)->orWhereIn('id', $scope->members()->where('is_active', true)->select('user_id')))->orderBy('name')->get(['id', 'name']),
            'permissions' => $permissions,
            'default_department_id' => $departmentId,
        ]]);
    }

    public function index(Request $request, Scope $scope): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'department_id' => ['nullable', 'ulid', Rule::exists('departments', 'id')->where('scope_id', $scope->id)],
            'thing_type_id' => ['nullable', 'ulid'],
            'condition' => ['nullable', Rule::in(Thing::CONDITIONS)],
            'page' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
        $query = $this->equip->constrain(Thing::query(), $this->context->actor($request), $scope)->with(['type:id,name', 'department:id,name,color,code', 'owner:id,name', 'supplierEntry:id,name', 'currentAssignment']);
        foreach (['department_id', 'thing_type_id', 'condition'] as $field) {
            if (! empty($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (! empty($data['q'])) {
            $query->where(function (Builder $query) use ($data): void {
                foreach (['name', 'manufacturer', 'model', 'serial_number', 'inventory_number', 'wms_product_id', 'wms_inventory_unit'] as $column) {
                    $query->orWhere($column, 'like', '%'.$data['q'].'%');
                }
            });
        }
        $sort = $data['sort'] ?? 'id';
        $direction = $data['direction'] ?? 'desc';
        $this->sort($query, $scope, $sort, $direction);
        if ($sort !== 'id') {
            $query->orderBy('things.id', $direction);
        }
        $page = $query->paginate(30);
        $covers = $this->covers($request, $scope, $page->getCollection()->modelKeys());
        foreach ($page as $thing) {
            $thing->setAttribute('cover_photo_id', $covers[$thing->id] ?? null);
        }

        return response()->json(['data' => $page->items(), 'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function show(Request $request, Scope $scope, Thing $thing): JsonResponse
    {
        abort_unless($this->equip->thing($this->context->actor($request), $scope, $thing), 404);

        return response()->json(['data' => $thing->load(['type', 'department:id,name,color,code', 'owner:id,name', 'supplierEntry:id,name', 'currentAssignment'])]);
    }

    public function store(SaveThingRequest $request, Scope $scope): JsonResponse
    {
        $actor = $this->context->actor($request);
        $data = $request->validated();
        $data['department_id'] ??= $this->access->membership($actor, $scope)?->department_id;
        abort_unless($this->equip->department($actor, $scope, $data['department_id']), 403, 'Нет доступа к оборудованию выбранного отдела.');
        abort_if(($data['container_kind'] ?? 'none') !== 'none' && ! $this->access->allows($actor, $scope, 'equip.compose'), 403, 'Для создания контейнера нужно право управления комплектацией.');
        $thing = DB::transaction(function () use ($request, $scope, $actor, $data): Thing {
            Scope::whereKey($scope->id)->lockForUpdate()->firstOrFail();
            $data = $this->resolveSupplier($scope, $data);
            $this->validateWmsUnit($scope, $data['wms_inventory_unit'] ?? null);
            $containerKind = $data['container_kind'] ?? 'none';
            unset($data['container_kind']);
            $thing = new Thing([...$data, 'scope_id' => $scope->id, 'created_by' => $actor->id]);
            $thing->container_kind = $containerKind;
            $thing->save();
            $this->record($request, $scope, $thing, 'equip.created', null, $thing->attributesToArray());

            return $thing;
        });

        return response()->json(['data' => $thing->load(['type', 'department:id,name,color,code', 'owner:id,name', 'supplierEntry:id,name', 'currentAssignment'])], 201);
    }

    public function update(SaveThingRequest $request, Scope $scope, Thing $thing): JsonResponse
    {
        $actor = $this->context->actor($request);
        abort_unless($this->equip->thing($actor, $scope, $thing, 'equip.update'), 404);
        $data = $request->validated();
        $thing = DB::transaction(function () use ($request, $scope, $thing, $actor, $data): Thing {
            Scope::whereKey($scope->id)->lockForUpdate()->firstOrFail();
            $locked = Thing::whereKey($thing->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->equip->thing($actor, $scope, $locked, 'equip.update'), 404);
            abort_if($locked->revision !== $data['revision'], 409, 'Карточка уже изменена. Закройте и откройте её заново перед сохранением.');
            if (array_key_exists('department_id', $data)) {
                abort_unless($this->equip->department($actor, $scope, $data['department_id']), 403, 'Нет доступа к оборудованию выбранного отдела.');
            }
            $data = $this->resolveSupplier($scope, $data);
            $before = $locked->attributesToArray();
            $locked->fill($data);
            foreach (['condition' => 'state', 'suitability' => 'state', 'owner_kind' => 'ownership', 'owner_id' => 'ownership', 'owner_name' => 'ownership'] as $field => $ability) {
                abort_if($locked->isDirty($field) && ! $this->access->allows($actor, $scope, 'equip.'.$ability), 403, 'Нет права на изменение состояния или собственника.');
            }
            $this->validateWmsUnit($scope, $locked->wms_inventory_unit, $locked->id);
            if ($locked->isDirty()) {
                $locked->revision++;
                $locked->save();
                $after = $locked->attributesToArray();
                $keys = array_keys($locked->getChanges());
                $this->record($request, $scope, $locked, 'equip.updated', array_intersect_key($before, array_flip($keys)), array_intersect_key($after, array_flip($keys)));
            }

            return $locked;
        });

        return response()->json(['data' => $thing->load(['type', 'department:id,name,color,code', 'owner:id,name', 'supplierEntry:id,name', 'currentAssignment'])]);
    }

    public function history(Request $request, Scope $scope, Thing $thing): JsonResponse
    {
        abort_unless($this->equip->thing($this->context->actor($request), $scope, $thing), 404);
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $history = ActivityLog::where('scope_id', $scope->id)->where('subject_type', 'thing')->where('subject_id', $thing->id)
            ->with('actor:id,name')->latest('id')->paginate(20);

        return response()->json(['data' => $history->map(fn (ActivityLog $event): array => [
            'id' => $event->id, 'action' => $event->action, 'created_at' => $event->created_at,
            'actor' => $event->actor?->only(['id', 'name']), 'before' => $event->before, 'after' => $event->after,
        ]), 'meta' => ['page' => $history->currentPage(), 'last_page' => $history->lastPage()]]);
    }

    public function storeType(Request $request, Scope $scope): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('thing_types')->where('scope_id', $scope->id)],
            'parent_id' => ['nullable', 'ulid', Rule::exists('thing_types', 'id')->where(fn ($query) => $query->whereNull('scope_id')->orWhere('scope_id', $scope->id))],
            'schema' => ['sometimes', 'array', 'max:50'],
            'schema.*' => ['array:key,label,type,unit'],
            'schema.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,49}$/', 'distinct'],
            'schema.*.label' => ['required', 'string', 'max:100'],
            'schema.*.type' => ['required', Rule::in(['text', 'number', 'boolean'])],
            'schema.*.unit' => ['nullable', 'string', 'max:30'],
        ]);

        return response()->json(['data' => ThingType::create([...$data, 'scope_id' => $scope->id])], 201);
    }

    private function sort(Builder $query, Scope $scope, string $sort, string $direction): void
    {
        $related = match ($sort) {
            'type' => ThingType::select('name')->whereColumn('thing_types.id', 'things.thing_type_id'),
            'department' => Department::selectRaw('COALESCE(NULLIF(code, ?), name)', [''])->whereColumn('departments.id', 'things.department_id'),
            'supplier' => ThingSupplier::select('name')->whereColumn('thing_suppliers.id', 'things.supplier_id'),
            'assignee' => ThingAssignment::select('user_name')->whereColumn('thing_id', 'things.id')->where('active', 1),
            default => null,
        };
        if ($related !== null) {
            $query->orderBy($related->limit(1), $direction);
        } elseif ($sort === 'owner') {
            $query->orderByRaw("CASE things.owner_kind WHEN 'employee' THEN (SELECT name FROM users WHERE users.id = things.owner_id) WHEN 'external' THEN things.owner_name ELSE ? END ".$direction, [$scope->name]);
        } elseif (in_array($sort, ['condition', 'suitability', 'container_kind'], true)) {
            $labels = match ($sort) {
                'condition' => ['working' => 'Исправно', 'maintenance' => 'Нужно обслуживание', 'broken' => 'Неисправно'],
                'suitability' => ['unassessed' => 'Не оценено', 'suitable' => 'Соответствует потребностям', 'limited' => 'Ограниченно пригодно', 'unsuitable' => 'Не соответствует потребностям'],
                'container_kind' => ['none' => 'Обычная вещь', 'physical' => 'Физический контейнер', 'logical' => 'Логический комплект'],
            };
            $sql = 'CASE things.'.$sort;
            $bindings = [];
            foreach ($labels as $value => $label) {
                $sql .= ' WHEN ? THEN ?';
                array_push($bindings, $value, $label);
            }
            $query->orderByRaw($sql.' END '.$direction, $bindings);
        } else {
            $query->orderBy('things.'.$sort, $direction);
        }
    }

    private function covers(Request $request, Scope $scope, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $actor = $this->context->actor($request);
        $covers = [];
        $files = FilerFile::where('scope_id', $scope->id)
            ->whereIn('mime', ['image/jpeg', 'image/png', 'image/webp'])
            ->whereHas('attachments', fn (Builder $query) => $query->where('subject_type', 'thing')->whereIn('subject_id', $ids))
            ->with('attachments.subject')->latest('id');
        foreach ($files->lazy(100) as $file) {
            $targets = $file->attachments->filter(fn ($link): bool => $link->subject_type === 'thing' && in_array($link->subject_id, $ids, true) && ! isset($covers[$link->subject_id]));
            if ($targets->isEmpty() || ! $this->files->file($actor, $scope, $file)) {
                continue;
            }
            foreach ($targets as $target) {
                $covers[$target->subject_id] = $file->id;
            }
            if (count($covers) === count($ids)) {
                break;
            }
        }

        return $covers;
    }

    private function resolveSupplier(Scope $scope, array $data): array
    {
        if (array_key_exists('supplier_id', $data)) {
            $data['supplier'] = $data['supplier_id'] ? ThingSupplier::where('scope_id', $scope->id)->findOrFail($data['supplier_id'])->name : null;
        } elseif (array_key_exists('supplier', $data)) {
            // Compatibility with cards opened before this release.
            $name = ThingSupplier::normalize($data['supplier'] ?? '');
            $supplier = $name === '' ? null : ThingSupplier::firstOrCreate(
                ['scope_id' => $scope->id, 'normalized_name' => mb_strtolower($name)], ['name' => $name],
            );
            $data['supplier_id'] = $supplier?->id;
            $data['supplier'] = $supplier?->name;
        }

        return $data;
    }

    private function validateWmsUnit(Scope $scope, ?string $unit, ?string $ignore = null): void
    {
        if ($unit !== null && Thing::where('scope_id', $scope->id)->where('wms_inventory_unit', $unit)->when($ignore, fn (Builder $query) => $query->where('id', '!=', $ignore))->exists()) {
            throw ValidationException::withMessages(['wms_inventory_unit' => 'Этот экземпляр WMS уже связан с оборудованием организации.']);
        }
    }

    private function record(Request $request, Scope $scope, Thing $thing, string $action, ?array $before, array $after): void
    {
        ActivityLog::create([
            'scope_id' => $scope->id, 'actor_id' => $this->context->actor($request)->id,
            'subject_type' => 'thing', 'subject_id' => $thing->id, 'action' => $action,
            'before' => $before, 'after' => $after, 'context' => $this->context->auditMetadata($request),
        ]);
    }
}
