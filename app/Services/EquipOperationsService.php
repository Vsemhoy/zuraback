<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Scope;
use App\Models\Thing;
use App\Models\ThingAssignment;
use App\Models\ThingComposition;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EquipOperationsService
{
    public function __construct(
        private readonly EquipAccessService $equip,
        private readonly ContractorContext $context,
    ) {}

    /** Includes logical groups, deduplicating components reached through multiple paths. */
    public function assembly(Scope $scope, Thing $root): Collection
    {
        $edges = ThingComposition::where('scope_id', $scope->id)->where('active', 1)->get(['parent_id', 'child_id'])->groupBy('parent_id');
        $ids = [$root->id => true];
        $queue = [$root->id];
        for ($i = 0; $i < count($queue); $i++) {
            foreach ($edges->get($queue[$i], []) as $edge) {
                if (! isset($ids[$edge->child_id])) {
                    $ids[$edge->child_id] = true;
                    $queue[] = $edge->child_id;
                    abort_if(count($queue) > 1000, 422, 'Комплект слишком большой для одной операции (максимум 1000 вещей).');
                }
            }
        }

        return Thing::where('scope_id', $scope->id)->whereIn('id', array_keys($ids))->with('currentAssignment')->orderBy('id')->get();
    }

    public function execute(Request $request, Scope $scope, Thing $thing, array $data): Thing
    {
        return DB::transaction(function () use ($request, $scope, $thing, $data): Thing {
            // All Equip mutations lock the scope first: serializes graph and assignment checks.
            Scope::whereKey($scope->id)->lockForUpdate()->firstOrFail();
            $root = Thing::whereKey($thing->id)->lockForUpdate()->firstOrFail();
            $actor = $this->context->actor($request);
            $ability = in_array($data['operation'], ['assign', 'return']) ? 'equip.assign' : 'equip.compose';
            abort_unless($this->equip->thing($actor, $scope, $root, $ability), 403, 'Нет права на эту операцию с оборудованием.');
            abort_unless($root->revision === $data['revision'], 409, 'Карточка изменилась. Обновите данные операции.');
            match ($data['operation']) {
                'assign', 'return' => $this->assign($request, $scope, $root, $data),
                'container' => $this->container($request, $scope, $root, $data),
                'attach' => $this->attach($request, $scope, $root, $data),
                'detach' => $this->detach($request, $scope, $root, $data),
                'disband' => $this->disband($request, $scope, $root, $data),
            };

            return $root->refresh()->load(['type', 'department:id,name,color', 'owner:id,name', 'supplierEntry:id,name', 'currentAssignment']);
        });
    }

    private function assign(Request $request, Scope $scope, Thing $root, array $data): void
    {
        $actor = $this->context->actor($request);
        $things = $this->assembly($scope, $root);
        foreach ($things as $thing) {
            abort_unless($this->equip->thing($actor, $scope, $thing, 'equip.assign'), 403, 'Для операции нужны права выдачи на весь состав, включая вложенные вещи.');
        }
        $expected = $data['expected_revisions'];
        $actual = $things->mapWithKeys(fn (Thing $thing): array => [$thing->id => $thing->revision])->all();
        ksort($expected);
        ksort($actual);
        abort_unless($expected === $actual, 409, 'Состав или назначения изменились. Обновите список перед подтверждением.');
        $person = null;
        if ($data['operation'] === 'assign') {
            $person = User::whereIn('type', ['real', 'virtual'])->where('status', 'active')->where('is_active', true)
                ->where(fn ($query) => $query->whereKey($scope->owner_id)->orWhereIn('id', $scope->members()->where('is_active', true)->select('user_id')))
                ->find($data['user_id']);
            if (! $person) {
                throw ValidationException::withMessages(['user_id' => 'Выберите активного сотрудника организации.']);
            }
        }
        $time = now();
        foreach ($things as $thing) {
            $previous = $thing->currentAssignment;
            if ((! $person && ! $previous) || ($person && $previous?->user_id === $person->id && $previous->responsibility === $data['responsibility'])) {
                continue;
            }
            $before = $previous ? ['responsible' => $previous->user_name, 'responsibility' => $previous->responsibility] : null;
            if ($previous) {
                $previous->update(['active' => null, 'ended_at' => $time, 'closed_by' => $actor->id, 'closing_note' => $data['note'] ?? null]);
            }
            if ($person) {
                ThingAssignment::create([
                    'scope_id' => $scope->id, 'thing_id' => $thing->id, 'user_id' => $person->id, 'user_name' => $person->name,
                    'responsibility' => $data['responsibility'], 'started_at' => $time, 'active' => 1,
                    'created_by' => $actor->id, 'note' => $data['note'] ?? null, 'batch_root_id' => $root->id,
                ]);
            }
            $thing->increment('revision');
            $this->record($request, $scope, $thing, $person ? ($previous ? 'equip.transferred' : 'equip.assigned') : 'equip.returned',
                $before, ['responsible' => $person?->name, 'responsibility' => $person ? $data['responsibility'] : null, 'batch_root' => $root->name, 'note' => $data['note'] ?? null]);
        }
    }

    private function container(Request $request, Scope $scope, Thing $root, array $data): void
    {
        if ($root->container_kind === $data['container_kind']) {
            return;
        }
        abort_if(ThingComposition::where('parent_id', $root->id)->where('active', 1)->exists(), 422, 'Сначала извлеките состав или расформируйте контейнер.');
        $before = ['container_kind' => $root->container_kind];
        $root->container_kind = $data['container_kind'];
        $root->revision++;
        $root->save();
        $this->record($request, $scope, $root, 'equip.container_changed', $before, ['container_kind' => $root->container_kind]);
    }

    private function attach(Request $request, Scope $scope, Thing $root, array $data): void
    {
        abort_if($root->container_kind === 'none', 422, 'Сначала выберите вид контейнера.');
        $child = Thing::where('scope_id', $scope->id)->findOrFail($data['child_id']);
        abort_unless($this->equip->thing($this->context->actor($request), $scope, $child, 'equip.compose'), 403, 'Нет права изменять комплектацию выбранной вещи.');
        abort_unless($child->revision === $data['child_revision'], 409, 'Компонент изменился. Выберите его заново.');
        abort_if($this->assembly($scope, $child)->contains('id', $root->id), 422, 'Нельзя включить вещь в себя или создать цикл вложенности.');
        abort_if(ThingComposition::where('parent_id', $root->id)->where('child_id', $child->id)->where('active', 1)->exists(), 422, 'Вещь уже входит в состав.');
        if ($root->container_kind === 'physical') {
            abort_if(ThingComposition::where('child_id', $child->id)->where('physical_active', 1)->exists(), 422, 'Вещь уже в физическом контейнере. Сначала извлеките её оттуда.');
        }
        $relation = ThingComposition::create([
            'scope_id' => $scope->id, 'parent_id' => $root->id, 'child_id' => $child->id,
            'parent_name' => $root->name, 'child_name' => $child->name, 'kind' => $root->container_kind,
            'started_at' => now(), 'active' => 1, 'physical_active' => $root->container_kind === 'physical' ? 1 : null,
            'created_by' => $this->context->actor($request)->id, 'note' => $data['note'] ?? null,
        ]);
        $root->increment('revision');
        $child->increment('revision');
        $details = ['container' => $root->name, 'component' => $child->name, 'relation_kind' => $relation->kind, 'note' => $relation->note];
        $this->record($request, $scope, $root, 'equip.attached', null, $details);
        $this->record($request, $scope, $child, 'equip.attached', null, $details);
    }

    private function detach(Request $request, Scope $scope, Thing $root, array $data): void
    {
        $relation = ThingComposition::where('scope_id', $scope->id)->where('active', 1)
            ->where(fn ($query) => $query->where('parent_id', $root->id)->orWhere('child_id', $root->id))
            ->findOrFail($data['relation_id']);
        $this->closeRelation($request, $scope, $relation, $data['note'] ?? null);
    }

    private function disband(Request $request, Scope $scope, Thing $root, array $data): void
    {
        foreach (ThingComposition::where('parent_id', $root->id)->where('active', 1)->get() as $relation) {
            $this->closeRelation($request, $scope, $relation, $data['note'] ?? null);
        }
    }

    private function closeRelation(Request $request, Scope $scope, ThingComposition $relation, ?string $note): void
    {
        $parent = Thing::findOrFail($relation->parent_id);
        $child = Thing::findOrFail($relation->child_id);
        $actor = $this->context->actor($request);
        abort_unless($this->equip->thing($actor, $scope, $parent, 'equip.compose') && $this->equip->thing($actor, $scope, $child, 'equip.compose'), 403, 'Нужны права на контейнер и компонент.');
        $relation->update(['active' => null, 'physical_active' => null, 'ended_at' => now(), 'closed_by' => $actor->id, 'closing_note' => $note]);
        $parent->increment('revision');
        $child->increment('revision');
        $details = ['container' => $parent->name, 'component' => $child->name, 'relation_kind' => $relation->kind];
        $this->record($request, $scope, $parent, 'equip.detached', $details, ['note' => $note]);
        $this->record($request, $scope, $child, 'equip.detached', $details, ['note' => $note]);
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
