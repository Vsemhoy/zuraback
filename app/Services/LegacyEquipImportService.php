<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\ExploitationEntry;
use App\Models\LegacyImportRecord;
use App\Models\Scope;
use App\Models\Thing;
use App\Models\ThingComposition;
use App\Models\ThingType;
use App\Models\User;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\Uid\Ulid;

class LegacyEquipImportService
{
    private const SOURCE = 'home.zuratax.com';

    private const TABLES = ['things', 'locations', 'registers', 'categories', 'contents', 'timer_entries', 'ledger_refs', 'expenses', 'event_refs'];

    private const SCHEMA = [
        ['key' => 'quantity', 'label' => 'Количество в прежней системе', 'type' => 'number'],
        ['key' => 'unit', 'label' => 'Единица измерения', 'type' => 'text'],
        ['key' => 'entity_type', 'label' => 'Вид в прежней системе', 'type' => 'text'],
        ['key' => 'legacy_status', 'label' => 'Статус в прежней системе', 'type' => 'text'],
        ['key' => 'url', 'label' => 'Ссылка', 'type' => 'text'],
        ['key' => 'archived', 'label' => 'Архив в прежней системе', 'type' => 'boolean'],
        ['key' => 'category_reference', 'label' => 'Отсутствующая старая категория', 'type' => 'text'],
    ];

    public function import(Scope $scope, array $snapshot, bool $commit = false): array
    {
        $rows = $this->validateSnapshot($snapshot);

        return DB::transaction(function () use ($scope, $snapshot, $rows, $commit): array {
            $scope = Scope::query()->lockForUpdate()->findOrFail($scope->id);
            $owner = User::query()->lockForUpdate()->findOrFail($scope->owner_id);
            if (! $scope->is_private || ! $scope->is_active || ! $owner->is_active || $owner->status !== 'active'
                || ! $scope->moduleEnabled('equip') || ! $scope->moduleEnabled('exploiter')
                || ! hash_equals($snapshot['owner_hash'], hash('sha256', Str::lower(trim((string) $owner->email))))) {
                throw new LogicException('A matching active owner and private Equip/Exploiter workspace are required.');
            }
            $ops = [];
            $maps = ['types' => [], 'categories' => [], 'locations' => [], 'things' => [], 'registers' => []];
            foreach (['item' => 'Вещи из прежней системы', 'location' => 'Места хранения из прежней системы'] as $kind => $name) {
                $op = $this->operation($scope, 'equip_'.$kind.'_type', Str::lower($snapshot['owner_id']), ThingType::class,
                    'thing_type', ['name' => $name, 'code' => 'legacy_'.$kind.'_'.$scope->id, 'schema' => self::SCHEMA], ['kind' => $kind]);
                $this->rejectUnmappedName($scope, $op, 'name');
                $ops[] = $op;
                $maps['types'][$kind] = $op['target_id'];
            }
            foreach ($this->ordered($rows['categories']) as $id => $row) {
                $parent = empty($row['parent_id']) ? null : ($maps['categories'][Str::lower($row['parent_id'])] ?? null);
                $op = $this->operation($scope, 'equip_category', $id, ThingType::class, 'thing_type', [
                    'name' => $row['name'], 'code' => 'legacy_category_'.$scope->id.'_'.$id,
                    'parent_id' => $parent, 'schema' => self::SCHEMA, ...Arr::only($row, ['created_at', 'updated_at']),
                ], $row);
                $this->rejectUnmappedName($scope, $op, 'name');
                $ops[] = $op;
                $maps['categories'][$id] = $op['target_id'];
            }
            foreach (['locations', 'things'] as $kind) {
                foreach ($rows[$kind] as $id => $row) {
                    $location = $kind === 'locations';
                    $category = empty($row['category_id']) ? null : Str::lower($row['category_id']);
                    $contents = $location ? [] : $this->relatedContent($rows['contents'], 'stuffer.thing', $id);
                    $primary = $this->primary($contents);
                    $description = $row['description'] ?? null;
                    if ($primary !== null && trim($primary) !== trim($description ?? '')) {
                        $description = trim(($description ?? '')."\n\n".$primary);
                    }
                    if (mb_strlen($description ?? '') > 20000) {
                        throw new LogicException('A thing description exceeds the native editor limit.');
                    }
                    $characteristics = ['archived' => (bool) ($row['is_archived'] ?? false)];
                    if (! $location) {
                        $characteristics += ['unit' => $row['unit'] ?? '',
                            'entity_type' => $row['entity_type'], 'legacy_status' => $row['current_status'], 'url' => $row['url'] ?? ''];
                        if (isset($row['qty'])) {
                            $characteristics['quantity'] = (float) $row['qty'];
                        }
                        if ($category !== null && ! isset($maps['categories'][$category])) {
                            $characteristics['category_reference'] = $category;
                        }
                    }
                    $hasChildren = collect($rows['things'])->contains(fn ($r): bool => Str::lower($r['parent_id'] ?? '') === $id);
                    $payload = ['name' => $row['name'], 'thing_type_id' => $location ? $maps['types']['location'] : ($maps['categories'][$category] ?? $maps['types']['item']),
                        'department_id' => null, 'owner_kind' => 'employee', 'owner_id' => $owner->id,
                        'container_kind' => $location ? 'physical' : ($hasChildren ? 'logical' : 'none'),
                        'registration_basis' => 'Перенос из home.zuratax.com. Исходные свойства сохранены в журнале импорта; техническое состояние не переоценивалось.',
                        'description' => $description, 'characteristics' => $characteristics,
                        ...Arr::only($row, ['created_at', 'updated_at'])];
                    if (! $location) {
                        $payload += ['manufacturer' => $row['vendor'] ?? null, 'serial_number' => $row['serial_no'] ?? null,
                            'purchased_on' => empty($row['purchase_date']) ? null : substr($row['purchase_date'], 0, 10),
                            'purchase_cost' => isset($row['purchase_price']) ? $this->money((int) $row['purchase_price']) : null];
                    }
                    $op = $this->operation($scope, $location ? 'equip_location' : 'equip_thing', $id, Thing::class, 'thing', $payload,
                        ['row' => $row, 'contents' => $contents, 'expenses' => $this->related($rows['expenses'], 'thing_id', $id),
                            'event_refs' => $this->related($rows['event_refs'], 'thing_id', $id)]);
                    $this->rejectUnmappedName($scope, $op, 'name');
                    $ops[] = $op;
                    $maps[$kind][$id] = $op['target_id'];
                }
            }
            foreach ($rows['locations'] as $id => $row) {
                if (! empty($row['parent_id'])) {
                    $parent = Str::lower($row['parent_id']);
                    $ops[] = $this->composition($scope, 'equip_place_tree', $id, $maps['locations'][$parent], $maps['locations'][$id],
                        $rows['locations'][$parent]['name'], $row['name'], 'physical', $row);
                }
            }
            foreach ($rows['things'] as $id => $row) {
                if (! empty($row['parent_id'])) {
                    $parent = Str::lower($row['parent_id']);
                    $ops[] = $this->composition($scope, 'equip_assembly', $id, $maps['things'][$parent], $maps['things'][$id],
                        $rows['things'][$parent]['name'], $row['name'], 'logical', $row);
                }
                if (! empty($row['current_location_id'])) {
                    $parent = Str::lower($row['current_location_id']);
                    $ops[] = $this->composition($scope, 'equip_placement', $id, $maps['locations'][$parent], $maps['things'][$id],
                        $rows['locations'][$parent]['name'], $row['name'], 'physical', $row);
                }
            }
            $warnings = ['missing_categories' => count($snapshot['missing_category_ids']), 'blocked_entries' => 0, 'incomplete_resources' => 0];
            foreach ($rows['registers'] as $id => $row) {
                $details = $this->decode($row['details'] ?? null);
                $contact = empty($row['performer_contact_id']) ? null : $this->contact($scope, Str::lower($row['performer_contact_id']));
                $timers = $this->related($rows['timer_entries'], 'source_id', $id);
                $contents = $this->relatedContent($rows['contents'], 'exploiter', $id);
                $sourceKind = $row['event_kind'] ?? 'note';
                $kind = $sourceKind;
                $annotations = [];
                if ($kind === 'consumption' && (empty($details['resource_type']) || ! is_numeric($details['amount'] ?? null)
                    || $details['amount'] <= 0 || empty($details['unit']))) {
                    $kind = 'note';
                    $warnings['incomplete_resources']++;
                    $annotations[] = 'Расход ресурса из прежней системы: неполные сведения о ресурсе, количестве или единице измерения. Значения не додумывались.';
                }
                if ($kind === 'reading' && (empty($details['metric']) || ! is_numeric($details['value'] ?? null) || empty($details['unit']))) {
                    throw new LogicException('An incomplete measurement requires a reviewed mapping.');
                }
                $status = [20 => 'planned', 21 => 'in_progress', 22 => 'done', 23 => 'planned', 24 => 'cancelled'][(int) ($row['status'] ?? 22)];
                if ((int) ($row['status'] ?? 0) === 23) {
                    $warnings['blocked_entries']++;
                    $annotations[] = '**Заблокировано в прежней системе. Запись не завершена.**';
                }
                $from = empty($row['from_location_id']) ? null : Str::lower($row['from_location_id']);
                $to = empty($row['to_location_id']) ? null : Str::lower($row['to_location_id']);
                if ($row['event_type'] !== 'repaired') {
                    $annotations[] = 'Исходная операция: '.$row['event_type'].'.';
                }
                foreach (['Из места' => $from, 'В место' => $to] as $label => $placeId) {
                    if ($placeId !== null) {
                        $annotations[] = $label.': '.$rows['locations'][$placeId]['name'].'.';
                    }
                }
                $body = $row['content_md'] ?? $this->primary($contents) ?? '';
                if ($details !== []) {
                    $annotations[] = "### Исходные подробности\n\n```json\n".json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n```";
                }
                if ($annotations !== []) {
                    $body .= "\n\n---\n\n".implode("\n\n", $annotations);
                }
                $payload = ['thing_id' => $maps['things'][Str::lower($row['thing_id'])], 'kind' => $kind, 'title' => $row['note'],
                    'occurred_on' => substr($row['occurred_at'], 0, 10), 'status' => $status,
                    'priority' => [11 => 'critical', 12 => 'high', 13 => 'normal', 14 => 'low', 15 => 'low'][(int) ($row['priority'] ?? 13)],
                    'content_md' => $body, 'result_md' => is_string($details['result'] ?? null) ? $details['result'] : null,
                    'performer_id' => null, 'performer_name' => $contact?->name ?? ($details['performer'] ?? $row['contact'] ?? null),
                    'part_cost' => $this->money((int) ($row['part_cost'] ?? 0)), 'labor_cost' => $this->money((int) ($row['labor_cost'] ?? 0)),
                    'own_minutes' => $timers === [] ? (int) ($row['time_self_min'] ?? 0) : collect($timers)->where('time_type', 'self')->sum('duration_min'),
                    'service_minutes' => $timers === [] ? (int) ($row['time_service_min'] ?? 0) : collect($timers)->where('time_type', 'service')->sum('duration_min'),
                    'resource_name' => $details['resource_type'] ?? null, 'quantity' => is_numeric($details['amount'] ?? null) && $details['amount'] > 0 ? $details['amount'] : null,
                    'unit' => $details['unit'] ?? null, 'metric_name' => $details['metric'] ?? null, 'reading_value' => $details['value'] ?? null,
                    'is_pinned' => (bool) ($row['is_pinned'] ?? false), ...Arr::only($row, ['created_at', 'updated_at'])];
                if (Validator::make($payload, ['content_md' => ['string', 'max:1000000'], 'performer_name' => ['nullable', 'string', 'max:255'],
                    'resource_name' => ['nullable', 'string', 'max:255'], 'unit' => ['nullable', 'string', 'max:30'],
                    'quantity' => ['nullable', 'numeric', 'min:0', 'max:9999999999.9999'], 'reading_value' => ['nullable', 'numeric']])->fails()) {
                    throw new LogicException('An exploitation exceeds native field limits.');
                }
                $archive = ['row' => $row, 'contents' => $contents, 'timers' => $timers,
                    'ledger_refs' => $this->related($rows['ledger_refs'], 'exploiter_event_id', $id),
                    'expenses' => $this->related($rows['expenses'], 'register_id', $id),
                    'event_refs' => $this->related($rows['event_refs'], 'exploiter_event_id', $id),
                    'resolved_contact_id' => $contact?->id, 'source_kind' => $sourceKind];
                $op = $this->operation($scope, 'equip_exploitation', $id, ExploitationEntry::class, 'exploitation_entry', $payload, $archive);
                $this->rejectUnmappedName($scope, $op, 'title', ['thing_id' => $payload['thing_id'], 'occurred_on' => $payload['occurred_on']]);
                $ops[] = $op;
                $maps['registers'][$id] = $op['target_id'];
                $ops[] = $this->operation($scope, 'equip_history', $id, ActivityLog::class, 'activity_log', [
                    'actor_id' => $owner->id, 'subject_type' => 'thing', 'subject_id' => $payload['thing_id'], 'action' => 'equip.legacy_exploitation',
                    'after' => ['exploitation_entry_id' => $op['target_id'], 'title' => $row['note'], 'event_type' => $row['event_type'],
                        'from_location_id' => $from ? $maps['locations'][$from] : null, 'to_location_id' => $to ? $maps['locations'][$to] : null,
                        'performer_contact_id' => $contact?->id],
                    'context' => ['import' => ['source' => self::SOURCE, 'external_id' => $id], 'occurred_at' => $row['occurred_at']],
                    'created_at' => $row['occurred_at'],
                ], $archive);
            }
            $counts = ['create' => [], 'skip' => []];
            foreach ($ops as $op) {
                $action = $op['registered'] ? 'skip' : 'create';
                $counts[$action][$op['kind']] = ($counts[$action][$op['kind']] ?? 0) + 1;
            }
            if ($commit) {
                $this->persist($scope, $ops);
            }

            return ['dry_run' => ! $commit, 'planned' => array_sum($counts['create']), 'created' => $commit ? array_sum($counts['create']) : 0,
                'counts' => $counts, 'warnings' => $warnings, 'maps' => $maps];
        });
    }

    private function persist(Scope $scope, array $ops): void
    {
        $batch = (string) Str::ulid();
        foreach ($ops as $op) {
            if ($op['registered']) {
                continue;
            }
            $class = $op['model'];
            $record = new $class;
            $payload = [...$op['payload'], 'id' => $op['target_id'], 'scope_id' => $scope->id];
            if (! $record instanceof ThingType && ! $record instanceof ActivityLog) {
                $payload['created_by'] = $scope->owner_id;
            }
            if ($record instanceof ExploitationEntry) {
                $payload['updated_by'] = $scope->owner_id;
            }
            $record->forceFill($payload)->saveQuietly();
            LegacyImportRecord::create(['scope_id' => $scope->id, 'created_by' => $scope->owner_id, 'batch_id' => $batch,
                'source' => self::SOURCE, 'source_type' => $op['kind'], 'source_id' => $op['source_id'],
                'target_type' => $op['target_type'], 'target_id' => $op['target_id'], 'source_hash' => $op['hash'], 'snapshot' => $op['archive']]);
        }
    }

    private function composition(Scope $scope, string $kind, string $id, string $parent, string $child, string $parentName, string $childName, string $relationKind, array $row): array
    {
        return $this->operation($scope, $kind, $id, ThingComposition::class, 'thing_composition', [
            'parent_id' => $parent, 'child_id' => $child, 'parent_name' => $parentName, 'child_name' => $childName,
            'kind' => $relationKind, 'started_at' => $row['updated_at'], 'active' => 1, 'physical_active' => $relationKind === 'physical' ? 1 : null,
            'note' => 'Текущее размещение/состав перенесено из прежней системы. Точный момент начала неизвестен; дата — время последнего изменения источника.',
            ...Arr::only($row, ['created_at', 'updated_at']),
        ], $row);
    }

    private function operation(Scope $scope, string $kind, string $sourceId, string $model, string $targetType, array $payload, array $archive): array
    {
        $hash = hash('sha256', json_encode($this->canonical($archive), JSON_THROW_ON_ERROR));
        $mapping = LegacyImportRecord::where('source', self::SOURCE)->where('source_type', $kind)->where('source_id', $sourceId)->lockForUpdate()->first();
        $query = in_array(SoftDeletes::class, class_uses_recursive($model), true) ? $model::withTrashed() : $model::query();
        $binary = substr(Ulid::fromString($sourceId)->toBinary(), 0, 6).substr(hash('sha256', self::SOURCE.'|'.$scope->id.'|'.$kind.'|'.$sourceId, true), 0, 10);
        $targetId = $mapping?->target_id ?? Str::lower(Ulid::fromBinary($binary)->toBase32());
        $target = $query->find($targetId);
        if ($mapping && ($mapping->scope_id !== $scope->id || $mapping->created_by !== $scope->owner_id || $mapping->target_type !== $targetType
            || ! hash_equals($mapping->source_hash, $hash) || ! $target)) {
            throw new LogicException('An equipment mapping changed, lost its target or points outside this workspace.');
        }
        if ($target && (! $mapping || $target->scope_id !== $scope->id
            || (! $target instanceof ThingType && ($target instanceof ActivityLog ? $target->actor_id : $target->created_by) !== $scope->owner_id))) {
            throw new LogicException('An equipment target ID is occupied or belongs to another owner.');
        }

        return ['kind' => $kind, 'source_id' => $sourceId, 'target_id' => $targetId, 'target_type' => $targetType,
            'model' => $model, 'payload' => $payload, 'archive' => $archive, 'hash' => $hash, 'registered' => $mapping !== null];
    }

    private function rejectUnmappedName(Scope $scope, array $op, string $field, array $where = []): void
    {
        if ($op['registered']) {
            return;
        }
        $class = $op['model'];
        $query = in_array(SoftDeletes::class, class_uses_recursive($class), true) ? $class::withTrashed() : $class::query();
        $records = $query->where('scope_id', $scope->id)->where($where)->limit(10001)->get([$field]);
        if ($records->count() > 10000 || $records->contains(fn ($row): bool => Str::lower(Str::squish($row->$field)) === Str::lower(Str::squish($op['payload'][$field])))) {
            throw new LogicException('An unregistered equipment name match requires manual reconciliation; nothing was duplicated.');
        }
    }

    private function contact(Scope $scope, string $id): Contact
    {
        $mapping = LegacyImportRecord::where('source', self::SOURCE)->where('source_type', 'contact')->where('source_id', $id)->where('scope_id', $scope->id)->first();
        $contact = $mapping ? Contact::find($mapping->target_id) : null;
        if (! $contact || $contact->scope_id !== $scope->id || $contact->created_by !== $scope->owner_id || $contact->visibility !== 'private') {
            throw new LogicException('A performer contact must first be imported privately into this workspace.');
        }

        return $contact;
    }

    private function validateSnapshot(array $snapshot): array
    {
        $header = ['schema' => ['required', 'in:1'], 'source' => ['required', 'in:'.self::SOURCE],
            'owner_id' => ['required', 'ulid'], 'owner_hash' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'missing_category_ids' => ['present', 'array', 'max:1000'], 'missing_category_ids.*' => ['string', 'max:128', 'distinct:ignore_case']];
        foreach (self::TABLES as $table) {
            $header[$table] = ['present', 'array', 'max:5000'];
        }
        if (Validator::make($snapshot, $header)->fails()) {
            throw new LogicException('Invalid equipment snapshot header.');
        }
        $rows = [];
        foreach (self::TABLES as $type) {
            $rows[$type] = [];
            foreach ($snapshot[$type] as $row) {
                $rules = ['id' => ['required', 'ulid'], 'user_id' => ['required', 'ulid'], 'created_at' => ['required', 'date'], 'updated_at' => ['required', 'date']];
                if ($type !== 'ledger_refs') {
                    $rules['deleted_at'] = ['nullable', 'in:'];
                }
                if (in_array($type, ['things', 'locations', 'categories'], true)) {
                    $rules += ['name' => ['required', 'string', 'max:'.($type === 'categories' ? 120 : 255)], 'parent_id' => ['nullable', 'ulid']];
                }
                if ($type === 'things') {
                    $rules += ['entity_type' => ['required', 'in:asset,item'], 'qty' => ['nullable', 'numeric', 'min:0'],
                        'visibility' => ['required', 'in:private'], 'current_status' => ['required', 'string', 'max:1000'],
                        'current_location_id' => ['nullable', 'ulid'], 'category_id' => ['nullable', 'string', 'max:128'], 'last_category_id' => ['nullable', 'string', 'max:128'],
                        'unit' => ['nullable', 'string', 'max:1000'], 'url' => ['nullable', 'string', 'max:1000'],
                        'vendor' => ['nullable', 'string', 'max:255'], 'serial_no' => ['nullable', 'string', 'max:255'],
                        'purchase_date' => ['nullable', 'date'], 'purchase_price' => ['nullable', 'integer', 'min:0', 'max:99999999999999']];
                }
                if ($type === 'registers') {
                    $rules += ['thing_id' => ['required', 'ulid'], 'event_type' => ['required', 'in:bought,received,returned,noted,ordered,moved,installed,lent,sold,lost,stolen,disposed,repaired'],
                        'event_kind' => ['nullable', 'in:work,incident,consumption,reading,inspection,note'], 'note' => ['required', 'string', 'max:255'],
                        'content_md' => ['nullable', 'string', 'max:1000000'], 'occurred_at' => ['required', 'date'],
                        'status' => ['nullable', 'in:20,21,22,23,24'], 'priority' => ['nullable', 'in:11,12,13,14,15'],
                        'performer_contact_id' => ['nullable', 'ulid'], 'from_location_id' => ['nullable', 'ulid'], 'to_location_id' => ['nullable', 'ulid'],
                        'part_cost' => ['nullable', 'integer', 'min:0', 'max:99999999999999'], 'labor_cost' => ['nullable', 'integer', 'min:0', 'max:99999999999999'],
                        'time_self_min' => ['nullable', 'integer', 'min:0'], 'time_service_min' => ['nullable', 'integer', 'min:0']];
                }
                if ($type === 'timer_entries') {
                    $rules += ['source_module' => ['required', 'in:exploiter'], 'source_id' => ['required', 'ulid'],
                        'time_type' => ['required', 'in:self,service'], 'duration_min' => ['required', 'integer', 'min:0']];
                }
                if ($type === 'contents') {
                    $rules += ['source_module' => ['required', 'in:exploiter,stuffer.thing'], 'source_id' => ['required', 'ulid'],
                        'body_md' => ['nullable', 'string', 'max:1000000'], 'field' => ['required', 'string'], 'kind' => ['required', 'string'], 'is_primary' => ['required', 'boolean']];
                }
                if (! is_array($row)) {
                    throw new LogicException('Invalid equipment row shape.');
                }
                $validator = Validator::make($row, $rules);
                if ($validator->fails()) {
                    throw new LogicException('Invalid '.$type.' fields: '.implode(', ', array_keys($validator->failed())).'.');
                }
                if (Str::lower($row['user_id']) !== Str::lower($snapshot['owner_id'])) {
                    throw new LogicException('Mixed equipment source owners.');
                }
                $id = Str::lower($row['id']);
                if (isset($rows[$type][$id])) {
                    throw new LogicException('Duplicate equipment source identities.');
                }
                $rows[$type][$id] = $row;
            }
        }
        $missing = array_fill_keys(array_map(Str::lower(...), $snapshot['missing_category_ids']), true);
        if (array_intersect_key($missing, $rows['categories']) !== []) {
            throw new LogicException('A declared missing category is present in the snapshot.');
        }
        $names = array_map(fn ($row): string => Str::lower(Str::squish($row['name'])), $rows['categories']);
        if (count($names) !== count(array_unique($names))) {
            throw new LogicException('Duplicate category names require reconciliation for the native type catalog.');
        }
        foreach (['things', 'locations', 'categories'] as $type) {
            foreach ($rows[$type] as $id => $row) {
                $seen = [$id => true];
                $parent = $row['parent_id'] ?? null;
                while ($parent) {
                    $parent = Str::lower($parent);
                    if ($type === 'categories' && isset($missing[$parent])) {
                        break;
                    }
                    if (! isset($rows[$type][$parent]) || isset($seen[$parent])) {
                        throw new LogicException('Missing or cyclic equipment hierarchy.');
                    }
                    $seen[$parent] = true;
                    $parent = $rows[$type][$parent]['parent_id'] ?? null;
                }
            }
        }
        foreach ($rows['things'] as $row) {
            $this->reference($rows['locations'], $row['current_location_id'] ?? null);
            foreach (['category_id', 'last_category_id'] as $field) {
                $id = Str::lower($row[$field] ?? '');
                if ($id !== '' && ! isset($rows['categories'][$id]) && ! isset($missing[$id])) {
                    throw new LogicException('An undeclared equipment category is missing.');
                }
            }
        }
        foreach ($rows['registers'] as $row) {
            $this->reference($rows['things'], $row['thing_id']);
            $this->reference($rows['locations'], $row['from_location_id'] ?? null);
            $this->reference($rows['locations'], $row['to_location_id'] ?? null);
            $this->decode($row['details'] ?? null);
        }
        foreach (['contents', 'timer_entries', 'ledger_refs', 'expenses', 'event_refs'] as $type) {
            foreach ($rows[$type] as $row) {
                if ($type === 'contents') {
                    $this->reference($row['source_module'] === 'exploiter' ? $rows['registers'] : $rows['things'], $row['source_id']);
                } elseif ($type === 'timer_entries') {
                    $this->reference($rows['registers'], $row['source_id']);
                } elseif ($type === 'ledger_refs') {
                    $this->reference($rows['registers'], $row['exploiter_event_id'] ?? null, true);
                } elseif ($type === 'event_refs') {
                    if (empty($row['thing_id']) && empty($row['exploiter_event_id'])) {
                        throw new LogicException('An event reference has no equipment endpoint.');
                    }
                    $this->reference($rows['things'], $row['thing_id'] ?? null);
                    $this->reference($rows['registers'], $row['exploiter_event_id'] ?? null);
                } else {
                    $this->reference($rows['things'], $row['thing_id'] ?? null, true);
                    $this->reference($rows['registers'], $row['register_id'] ?? null);
                }
            }
        }

        return $rows;
    }

    private function reference(array $rows, ?string $id, bool $required = false): void
    {
        if (($required && ! $id) || ($id && ! isset($rows[Str::lower($id)]))) {
            throw new LogicException('An equipment relationship points outside this snapshot.');
        }
    }

    private function ordered(array $rows): array
    {
        $ordered = [];
        while ($rows !== []) {
            foreach ($rows as $id => $row) {
                $parent = Str::lower($row['parent_id'] ?? '');
                if ($parent === '' || ! isset($rows[$parent])) {
                    $ordered[$id] = $row;
                    unset($rows[$id]);
                }
            }
        }

        return $ordered;
    }

    private function related(array $rows, string $field, string $id): array
    {
        return array_values(array_filter($rows, fn ($row): bool => Str::lower($row[$field] ?? '') === $id));
    }

    private function relatedContent(array $rows, string $module, string $id): array
    {
        return array_values(array_filter($this->related($rows, 'source_id', $id), fn ($row): bool => $row['source_module'] === $module));
    }

    private function primary(array $contents): ?string
    {
        $primary = array_values(array_filter($contents, fn ($row): bool => $row['field'] === 'content' && $row['kind'] === 'markdown' && (bool) $row['is_primary']));
        if (count($primary) > 1) {
            throw new LogicException('Ambiguous primary equipment content.');
        }

        return $primary[0]['body_md'] ?? null;
    }

    private function money(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    private function decode(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $value;
        if ($decoded !== null && ! is_array($decoded)) {
            throw new LogicException('Invalid structured equipment details.');
        }

        return $decoded ?? [];
    }

    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonical($item);
            }
        }

        return $value;
    }
}
