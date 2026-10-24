<?php

namespace App\Services;

use App\Http\Requests\Api\SaveContactRequest;
use App\Http\Requests\Api\StoreFactRequest;
use App\Models\Contact;
use App\Models\ContactNote;
use App\Models\EntityLink;
use App\Models\Fact;
use App\Models\LegacyImportRecord;
use App\Models\Scope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use LogicException;

class LegacyPersonalImportService
{
    public const SOURCE = 'home.zuratax.com';

    /** CLI-only: the snapshot owner must match the active private-scope owner. */
    public function import(Scope $scope, array $snapshot, bool $commit = false): array
    {
        $operations = $this->operations($snapshot);

        return DB::transaction(function () use ($scope, $snapshot, $operations, $commit): array {
            $scope = Scope::query()->lockForUpdate()->findOrFail($scope->id);
            $owner = User::query()->lockForUpdate()->findOrFail($scope->owner_id);
            if (! $scope->is_private || ! $scope->is_active || ! $owner->is_active || $owner->status !== 'active'
                || ! $scope->moduleEnabled('contactor') || ! $scope->moduleEnabled('factor')
                || ! hash_equals($snapshot['owner_hash'], hash('sha256', Str::lower(trim((string) $owner->email))))) {
                throw new LogicException('Import requires a matching active owner and a private enabled workspace.');
            }
            $batch = (string) Str::ulid();
            $counts = ['create' => [], 'skip' => []];
            $pending = [];
            foreach ($operations as $op) {
                $existing = LegacyImportRecord::query()->where('source', self::SOURCE)
                    ->where('source_type', $op['source_type'])->where('source_id', $op['source_id'])->lockForUpdate()->first();
                $model = $op['model'];
                $query = in_array(SoftDeletes::class, class_uses_recursive($model), true) ? $model::withTrashed() : $model::query();
                $target = $query->find($op['source_id']);
                if ($existing !== null) {
                    if ($existing->scope_id !== $scope->id || $existing->created_by !== $owner->id
                        || $existing->target_type !== $op['target_type'] || $existing->target_id !== $op['source_id']
                        || ! hash_equals($existing->source_hash, $op['hash']) || $target === null
                        || $target->created_by !== $owner->id || $this->targetScope($target) !== $scope->id) {
                        throw new LogicException('An imported source changed or its target no longer matches. No records were changed.');
                    }
                    $counts['skip'][$op['source_type']] = ($counts['skip'][$op['source_type']] ?? 0) + 1;
                } else {
                    if ($target !== null) {
                        throw new LogicException('A target ID is already used by a non-imported record. No records were changed.');
                    }
                    $counts['create'][$op['source_type']] = ($counts['create'][$op['source_type']] ?? 0) + 1;
                    $pending[] = $op;
                }
            }
            if ($commit) {
                foreach ($pending as $op) {
                    $model = $op['model'];
                    $record = new $model;
                    $attributes = [...$op['payload'], 'id' => $op['source_id'], 'created_by' => $owner->id];
                    if (! $record instanceof ContactNote) {
                        $attributes['scope_id'] = $scope->id;
                    }
                    // Preserve historical timestamps without invoking write-side observers.
                    $record->forceFill($attributes)->saveQuietly();
                    LegacyImportRecord::create([
                        'scope_id' => $scope->id, 'created_by' => $owner->id, 'batch_id' => $batch,
                        'source' => self::SOURCE, 'source_type' => $op['source_type'], 'source_id' => $op['source_id'],
                        'target_type' => $op['target_type'], 'target_id' => $record->id,
                        'source_hash' => $op['hash'], 'snapshot' => $op['snapshot'],
                    ]);
                }
            }

            return ['dry_run' => ! $commit, 'counts' => $counts, 'created' => $commit ? count($pending) : 0, 'planned' => count($pending)];
        });
    }

    private function targetScope(Model $record): ?string
    {
        return $record instanceof ContactNote ? $record->contact()->withTrashed()->value('scope_id') : $record->scope_id;
    }

    private function operations(array $snapshot): array
    {
        if (Validator::make($snapshot, [
            'schema' => ['required', 'integer', 'in:1'], 'source' => ['required', 'in:'.self::SOURCE],
            'owner_id' => ['required', 'ulid'], 'owner_hash' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'contacts' => ['present', 'array', 'max:5000'], 'notes' => ['present', 'array', 'max:10000'],
            'facts' => ['present', 'array', 'max:5000'], 'details' => ['present', 'array', 'max:10000'],
            'relations' => ['present', 'array', 'max:5000'],
        ])->fails()) {
            throw new LogicException('Invalid snapshot header or collection sizes.');
        }
        $owner = Str::lower($snapshot['owner_id']);
        $rows = [];
        foreach (['contacts', 'notes', 'facts', 'details', 'relations'] as $type) {
            $rows[$type] = [];
            foreach ($snapshot[$type] as $row) {
                if (! is_array($row) || Validator::make($row, [
                    'id' => ['required', 'ulid'], 'user_id' => ['required', 'ulid'],
                    'created_at' => ['required', 'date'], 'updated_at' => ['required', 'date'], 'deleted_at' => ['nullable', 'in:'],
                ])->fails() || Str::lower($row['user_id']) !== $owner) {
                    throw new LogicException('Invalid source row, deleted row, or mixed source owners.');
                }
                $id = Str::lower($row['id']);
                if (isset($rows[$type][$id])) {
                    throw new LogicException('Duplicate source IDs.');
                }
                $rows[$type][$id] = $row;
            }
        }
        $operations = [];
        foreach ($rows['contacts'] as $id => $row) {
            $payload = Arr::only($row, ['name', 'nickname', 'company', 'role', 'met_context', 'last_contact_at', 'is_pinned', 'is_archived', 'sort_order', 'created_at', 'updated_at']);
            $annotations = [];
            if (! empty($row['group'])) {
                $annotations[] = 'Группа в прежнем Контакторе: '.$row['group'];
            }
            foreach (['met_at' => 'met_precision', 'birthday_on' => 'birthday_precision'] as $date => $precision) {
                $payload[$date] = $row[$date] ?? null;
                if (! empty($row[$date]) && ($row[$precision] ?? 'day') !== 'day') {
                    $payload[$date] = null;
                    $annotations[] = ($date === 'met_at' ? 'Дата знакомства' : 'Дата рождения').': '.$row[$date].' (точность: '.$row[$precision].').';
                }
            }
            $payload = [...$payload, 'kind' => 'person', 'note_md' => implode("\n\n", $annotations) ?: null,
                'visibility' => 'private', 'project_id' => null, 'user_id' => null, 'allow_member_editing' => false, 'revision' => 1];
            $this->validatePayload($payload, (new SaveContactRequest)->rules(), 'contact');
            $operations[] = $this->operation('contact', $id, Contact::class, 'contact', $payload, $row);
        }
        foreach ($rows['notes'] as $id => $row) {
            $contactId = $this->contactId($row['contact_id'] ?? null, $rows['contacts']);
            $payload = ['contact_id' => $contactId, 'title' => $row['title'] ?? null, 'content' => $row['body_md'] ?? '',
                'occurred_at' => $row['occurred_at'] ?? $row['created_at'], ...Arr::only($row, ['created_at', 'updated_at'])];
            $this->validatePayload($payload, ['title' => ['nullable', 'string', 'max:255'], 'content' => ['present', 'string', 'max:200000'], 'occurred_at' => ['required', 'date']], 'note');
            $operations[] = $this->operation('contact_note', $id, ContactNote::class, 'contact_note', $payload, $row);
        }
        foreach ($rows['facts'] as $id => $row) {
            $payload = Arr::only($row, ['label', 'value', 'format', 'language', 'unit', 'context', 'search_keywords', 'kind', 'display_mode', 'is_sensitive', 'is_expert', 'is_pinned', 'valid_from', 'valid_to', 'sort_order', 'created_at', 'updated_at']);
            if (is_string($payload['search_keywords'] ?? null)) {
                $payload['search_keywords'] = json_decode($payload['search_keywords'], true, 512, JSON_THROW_ON_ERROR);
            }
            $payload = [...$payload, 'visibility' => 'private', 'project_id' => null, 'allow_member_editing' => false, 'revision' => 1];
            $this->validatePayload($payload, (new StoreFactRequest)->rules(), 'fact');
            $operations[] = $this->operation('fact', $id, Fact::class, 'fact', $payload, $row);
        }
        foreach ($rows['details'] as $id => $row) {
            $contactId = $this->contactId($row['contact_id'] ?? null, $rows['contacts']);
            $payload = ['label' => ($row['label'] ?? null) ?: ($row['kind'] ?? 'Реквизит'), 'value' => $row['value'] ?? '',
                'kind' => $row['kind'] ?? 'other', 'format' => 'text', 'display_mode' => 'plain', 'is_sensitive' => true,
                'visibility' => 'private', 'project_id' => null, 'allow_member_editing' => false, 'revision' => 1,
                'sort_order' => $row['sort_order'] ?? 0, ...Arr::only($row, ['created_at', 'updated_at'])];
            $this->validatePayload($payload, (new StoreFactRequest)->rules(), 'contact detail');
            $operations[] = $this->operation('contact_detail', $id, Fact::class, 'fact', $payload, $row);
            $operations[] = $this->operation('contact_detail_link', $id, EntityLink::class, 'entity_link', [
                'source_type' => 'contact', 'source_id' => $contactId, 'target_type' => 'fact', 'target_id' => $id,
                'relation' => 'related', 'note' => 'Реквизит контакта из прежней системы', ...Arr::only($row, ['created_at', 'updated_at']),
            ], $row);
        }
        foreach ($rows['relations'] as $id => $row) {
            $first = $this->contactId($row['contact_a_id'] ?? null, $rows['contacts']);
            $second = $this->contactId($row['contact_b_id'] ?? null, $rows['contacts']);
            if ($first === $second) {
                throw new LogicException('Self-linked legacy contact relation.');
            }
            $operations[] = $this->operation('contact_relation', $id, EntityLink::class, 'entity_link', [
                'source_type' => 'contact', 'source_id' => $first, 'target_type' => 'contact', 'target_id' => $second,
                'relation' => 'legacy_relation', 'note' => implode("\n\n", array_filter(Arr::only($row, ['kind', 'context', 'note']))),
                'meta' => Arr::only($row, ['kind', 'valid_from', 'valid_to']), ...Arr::only($row, ['created_at', 'updated_at']),
            ], $row);
        }
        $identities = array_map(fn (array $op): string => $op['target_type'].':'.$op['source_id'], $operations);
        if (count($identities) !== count(array_unique($identities))) {
            throw new LogicException('Multiple source records resolve to the same target.');
        }

        return $operations;
    }

    private function contactId(mixed $id, array $contacts): string
    {
        if (! is_string($id) || ! isset($contacts[Str::lower($id)])) {
            throw new LogicException('A referenced contact is not part of this snapshot.');
        }

        return Str::lower($id);
    }

    private function validatePayload(array $payload, array $rules, string $type): void
    {
        $validator = Validator::make($payload, $rules);
        if ($validator->fails()) {
            throw new LogicException('Invalid '.$type.' fields: '.implode(', ', array_keys($validator->errors()->messages())).'.');
        }
    }

    private function operation(string $type, string $id, string $model, string $targetType, array $payload, array $snapshot): array
    {
        return ['source_type' => $type, 'source_id' => $id, 'model' => $model, 'target_type' => $targetType,
            'payload' => $payload, 'snapshot' => $snapshot,
            'hash' => hash('sha256', json_encode($this->canonical(['payload' => $payload, 'snapshot' => $snapshot]), JSON_THROW_ON_ERROR))];
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
