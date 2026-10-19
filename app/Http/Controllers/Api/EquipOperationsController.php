<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\EquipOperationRequest;
use App\Models\Scope;
use App\Models\Thing;
use App\Models\ThingAssignment;
use App\Models\ThingComposition;
use App\Models\ThingSupplier;
use App\Services\ContractorAccessService;
use App\Services\ContractorContext;
use App\Services\EquipAccessService;
use App\Services\EquipOperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EquipOperationsController extends Controller
{
    public function __construct(
        private readonly EquipAccessService $equip,
        private readonly ContractorAccessService $access,
        private readonly ContractorContext $context,
        private readonly EquipOperationsService $operations,
    ) {}

    public function show(Request $request, Scope $scope, Thing $thing): JsonResponse
    {
        $actor = $this->context->actor($request);
        abort_unless($this->equip->thing($actor, $scope, $thing), 404);
        $assembly = $this->operations->assembly($scope, $thing);
        $canAssign = $assembly->every(fn (Thing $item): bool => $this->equip->thing($actor, $scope, $item, 'equip.assign'));
        $visible = $assembly->filter(fn (Thing $item): bool => $this->equip->thing($actor, $scope, $item));
        $relations = ThingComposition::where('scope_id', $scope->id)->where('active', 1)
            ->where(fn ($query) => $query->where('parent_id', $thing->id)->orWhere('child_id', $thing->id))->get();
        $linked = $this->equip->constrain(Thing::query(), $actor, $scope)->whereIn('id', $relations->pluck('child_id')->merge($relations->pluck('parent_id')))
            ->get()->keyBy('id');

        return response()->json(['data' => [
            'revision' => $thing->revision,
            'container_kind' => $thing->container_kind,
            'can_assign' => $canAssign,
            'hidden_components' => $visible->count() !== $assembly->count(),
            'assembly' => $visible->map(fn (Thing $item): array => [
                'id' => $item->id, 'name' => $item->name, 'inventory_number' => $item->inventory_number,
                'revision' => $item->revision, 'current_assignment' => $item->currentAssignment,
            ])->values(),
            'relations' => $relations->map(function (ThingComposition $relation) use ($linked, $thing, $actor, $scope): array {
                $other = $linked->get($relation->parent_id === $thing->id ? $relation->child_id : $relation->parent_id);

                return [
                    'id' => $relation->id, 'direction' => $relation->parent_id === $thing->id ? 'child' : 'parent',
                    'kind' => $relation->kind, 'started_at' => $relation->started_at,
                    'thing' => $other?->only(['id', 'name', 'inventory_number']),
                    'can_detach' => $other && $this->equip->thing($actor, $scope, $thing, 'equip.compose') && $this->equip->thing($actor, $scope, $other, 'equip.compose'),
                ];
            }),
        ]]);
    }

    public function store(EquipOperationRequest $request, Scope $scope, Thing $thing): JsonResponse
    {
        return response()->json(['data' => $this->operations->execute($request, $scope, $thing, $request->validated())]);
    }

    public function assignments(Request $request, Scope $scope, Thing $thing): JsonResponse
    {
        abort_unless($this->equip->thing($this->context->actor($request), $scope, $thing), 404);
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $page = ThingAssignment::where('scope_id', $scope->id)->where('thing_id', $thing->id)->latest('id')->paginate(20);

        return response()->json(['data' => $page->items(), 'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function compositions(Request $request, Scope $scope, Thing $thing): JsonResponse
    {
        $actor = $this->context->actor($request);
        abort_unless($this->equip->thing($actor, $scope, $thing), 404);
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $page = ThingComposition::where('scope_id', $scope->id)
            ->where(fn ($query) => $query->where('parent_id', $thing->id)->orWhere('child_id', $thing->id))->latest('id')->paginate(20);

        return response()->json(['data' => $page->items(), 'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function suppliers(Request $request, Scope $scope): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120']]);
        $query = ThingSupplier::where('scope_id', $scope->id);
        if (! empty($data['q'])) {
            $query->where('name', 'like', '%'.$data['q'].'%');
        }

        return response()->json(['data' => $query->orderBy('name')->limit(30)->get(['id', 'name'])]);
    }

    public function storeSupplier(Request $request, Scope $scope): JsonResponse
    {
        $actor = $this->context->actor($request);
        abort_unless($this->access->allows($actor, $scope, 'equip.create') || $this->access->allows($actor, $scope, 'equip.update'), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $name = ThingSupplier::normalize($data['name']);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Введите название поставщика.']);
        }
        $supplier = DB::transaction(function () use ($scope, $name): ThingSupplier {
            Scope::whereKey($scope->id)->lockForUpdate()->firstOrFail();

            return ThingSupplier::firstOrCreate(['scope_id' => $scope->id, 'normalized_name' => mb_strtolower($name)], ['name' => $name]);
        });

        return response()->json(['data' => $supplier->only(['id', 'name'])], $supplier->wasRecentlyCreated ? 201 : 200);
    }
}
