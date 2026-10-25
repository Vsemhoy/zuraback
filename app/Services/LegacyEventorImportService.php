<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\ContentCategory;
use App\Models\EntityLink;
use App\Models\Event;
use App\Models\EventSection;
use App\Models\EventType;
use App\Models\LegacyImportRecord;
use App\Models\Project;
use App\Models\Scope;
use App\Models\Task;
use App\Models\Thing;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\Uid\Ulid;

class LegacyEventorImportService
{
    private const SOURCE = 'home.zuratax.com';

    private const TABLES = ['sections', 'categories', 'types', 'events', 'contents', 'tags', 'event_tags', 'contacts', 'task_refs', 'media', 'embeds', 'starred'];

    public function import(Scope $scope, array $snapshot, bool $commit = false): array
    {
        $rows = $this->validateSnapshot($snapshot);

        return DB::transaction(function () use ($scope, $snapshot, $rows, $commit): array {
            $scope = Scope::query()->lockForUpdate()->findOrFail($scope->id);
            $owner = User::query()->lockForUpdate()->findOrFail($scope->owner_id);
            if (! $scope->is_private || ! $scope->is_active || ! $owner->is_active || $owner->status !== 'active' || ! $scope->moduleEnabled('eventor')
                || ! hash_equals($snapshot['owner_hash'], hash('sha256', Str::lower(trim((string) $owner->email))))) {
                throw new LogicException('A matching active owner and private Eventor workspace are required.');
            }
            $maps = ['sections' => [], 'categories' => [], 'types' => [], 'events' => []];
            $ops = [];
            foreach (['sections' => EventSection::class, 'categories' => ContentCategory::class, 'types' => EventType::class] as $kind => $class) {
                $existing = $class::where('scope_id', $scope->id)->get();
                $catalogRows = $rows[$kind];
                uasort($catalogRows, fn ($a, $b): int => (int) $a['sort_order'] <=> (int) $b['sort_order'] ?: strcmp($a['id'], $b['id']));
                $rank = 0;
                foreach ($catalogRows as $id => $row) {
                    $payload = Arr::only($row, ['name', 'description', 'color', 'sort_order', 'is_archived', 'created_at', 'updated_at']);
                    $payload['sort_order'] = $rank++;
                    $payload['created_at'] = $row['created_at'] ?? $row['updated_at'];
                    $payload['color'] = $this->color($row['color'] ?? $row['bgcolor'] ?? null);
                    if ($kind !== 'categories') {
                        $payload += ['background_color' => $this->color($row['bgcolor'] ?? null), 'icon' => $row['icon'] ?? null, 'is_default' => false];
                    }
                    if ($kind === 'sections') {
                        $payload += ['visibility' => 'private', 'slug' => 'legacy-'.$id, 'decor' => $this->decode($row['decor'] ?? null), 'seo' => $this->decode($row['seo'] ?? null)];
                    } elseif ($kind === 'types') {
                        $payload['code'] = 'old_'.substr(hash('sha256', $id), 0, 16);
                    }
                    $op = $this->operation($scope, 'eventor_'.Str::singular($kind), $id, $class, $payload, $row);
                    if (! $op['registered'] && $existing->contains(fn ($r): bool => $this->normalized($r->name) === $this->normalized($row['name']))) {
                        throw new LogicException('An unregistered catalog name match requires manual reconciliation; nothing was duplicated.');
                    }
                    $ops[] = $op;
                    $maps[$kind][$id] = $op['target_id'];
                }
            }
            $contents = collect($rows['contents'])->groupBy(fn ($c) => Str::lower($c['source_id']));
            $tagRefs = collect($rows['event_tags'])->groupBy(fn ($t) => Str::lower($t['event_id']));
            $orderedEvents = $rows['events'];
            uasort($orderedEvents, fn ($a, $b): int => (int) $a['sort_order'] <=> (int) $b['sort_order'] ?: strcmp($a['id'], $b['id']));
            $eventRanks = array_flip(array_keys($orderedEvents));
            foreach ($rows['events'] as $id => $row) {
                $blocks = ($contents[$id] ?? collect())->values()->all();
                $refs = ($tagRefs[$id] ?? collect())->values()->all();
                $tags = array_map(fn ($ref): array => $rows['tags'][Str::lower($ref['tag_id'])], $refs);
                $content = $row['content'] ?? null;
                foreach ($blocks as $block) {
                    if ($block['is_primary'] && $block['field'] === 'content' && $block['kind'] === 'markdown') {
                        if (filled($content) && filled($block['body_md']) && $content !== $block['body_md']) {
                            throw new LogicException('An event has conflicting primary Markdown sources.');
                        }
                        $content = $block['body_md'] ?? $content;
                    }
                }
                $project = empty($row['project_id']) ? null : $this->external($scope, 'tasker_project', $row['project_id'], Project::class);
                $thing = empty($row['thing_id']) ? null : $this->external($scope, 'equip_thing', $row['thing_id'], Thing::class);
                if (! empty($row['exploiter_event_id'])) {
                    throw new LogicException('An exploitation reference needs a native Eventor relation before this batch can be imported.');
                }
                $payload = [...Arr::only($row, ['language', 'code_language', 'location', 'occurred_at', 'is_pinned', 'is_locked', 'is_blurred', 'is_expert', 'sort_order', 'created_at', 'updated_at']),
                    'title' => trim($row['name'] ?? '') ?: 'Без названия', 'content' => $content, 'sort_order' => $eventRanks[$id],
                    'format' => [0 => 'markdown', 1 => 'markdown', 2 => 'text', 3 => 'code'][(int) $row['format']],
                    'status' => [0 => 'draft', 1 => 'published', 2 => 'archived'][(int) $row['status']],
                    'visibility' => 'private', 'comments_enabled' => false, 'importance' => 'undefined', 'project_id' => $project?->id,
                    'section_id' => empty($row['section_id']) ? null : $maps['sections'][Str::lower($row['section_id'])],
                    'category_id' => empty($row['category_id']) ? null : $maps['categories'][Str::lower($row['category_id'])],
                    'type_id' => empty($row['type_id']) ? null : $maps['types'][Str::lower($row['type_id'])], 'relation_type' => (string) $row['relation_type'],
                    'meta' => ['import' => ['source' => self::SOURCE, 'external_id' => $id], 'legacy_client' => $row['client'] ?? null,
                        'legacy_metadata' => $row['metadata'] ?? null, 'legacy_thing_id' => $thing?->id,
                        'tags' => array_map(fn ($t): array => Arr::only($t, ['id', 'name', 'slug', 'color', 'bgcolor']), $tags)]];
                $archive = ['row' => $row, 'contents' => $blocks, 'tags' => $tags, 'event_tags' => $refs];
                $registered = LegacyImportRecord::where('source', self::SOURCE)->where('source_type', 'eventor_event')->where('source_id', $id)->exists();
                $matches = $registered ? collect() : Event::withTrashed()->where('scope_id', $scope->id)->where(function ($q) use ($id): void {
                    $q->whereKey($id)->orWhere('meta->import->external_id', $id);
                })->get();
                if (! $registered && $matches->isEmpty()) {
                    $matches = Event::withTrashed()->where('scope_id', $scope->id)->where('title', $payload['title'])->where('occurred_at', $payload['occurred_at'])->get();
                }
                if ($matches->count() > 1) {
                    throw new LogicException('Ambiguous existing event matches.');
                }
                $candidate = $matches->first();
                if ($candidate && ($candidate->trashed() || $candidate->visibility !== 'private'
                    || ($candidate->id !== $id && ($candidate->meta['import']['external_id'] ?? '') !== $id
                        && ($candidate->content !== $content || $candidate->getRawOriginal('created_at') !== $row['created_at']
                            || $candidate->section_id !== $payload['section_id'] || $candidate->project_id !== $payload['project_id'])))) {
                    throw new LogicException('An event match lacks reliable provenance or is not private.');
                }
                $op = $this->operation($scope, 'eventor_event', $id, Event::class, $payload, $archive, $candidate);
                $maps['events'][$id] = $op['target_id'];
                $ops[] = $op;
                if ($thing) {
                    $ops[] = $this->link($scope, 'eventor_thing_link', $id, 'event', $op['target_id'], 'thing', $thing->id, 'related', $row);
                }
            }
            foreach ($ops as &$op) {
                if ($op['class'] !== Event::class) {
                    continue;
                }
                $row = $rows['events'][$op['source_id']];
                $root = $op['source_id'];
                while (! empty($rows['events'][$root]['parent_id'])) {
                    $root = Str::lower($rows['events'][$root]['parent_id']);
                }
                $op['payload'] += ['parent_id' => empty($row['parent_id']) ? null : $maps['events'][Str::lower($row['parent_id'])], 'root_id' => $maps['events'][$root]];
                if ($op['reuse'] && ! $op['registered'] && Event::findOrFail($op['target_id'])->parent_id !== $op['payload']['parent_id']) {
                    throw new LogicException('A reused event has a conflicting parent.');
                }
            }
            unset($op);
            foreach ($rows['contacts'] as $id => $row) {
                $contact = $this->external($scope, 'contact', $row['contact_id'], Contact::class);
                $ops[] = $this->link($scope, 'eventor_contact_link', $id, 'event', $maps['events'][Str::lower($row['event_id'])], 'contact', $contact->id, 'related', $row,
                    ['note' => $row['note'] ?? null, 'meta' => ['legacy_role' => $row['role'] ?? null, 'legacy_sort_order' => $row['sort_order'] ?? 0]]);
            }
            foreach ($rows['task_refs'] as $id => $row) {
                $task = $this->external($scope, 'tasker_task', $id, Task::class);
                $ops[] = $this->link($scope, 'eventor_task_link', $id, 'task', $task->id, 'event', $maps['events'][Str::lower($row['eventor_event_id'])], 'related', $row);
            }
            foreach (LegacyImportRecord::where('source', self::SOURCE)->where('source_type', 'contact_note')->where('scope_id', $scope->id)->get() as $note) {
                $raw = $note->snapshot;
                $eventId = Str::lower($raw['eventor_event_id'] ?? '');
                if (isset($maps['events'][$eventId])) {
                    $contact = $this->external($scope, 'contact', $raw['contact_id'], Contact::class);
                    $ops[] = $this->link($scope, 'eventor_contact_note_link', $note->source_id, 'contact', $contact->id, 'event', $maps['events'][$eventId], 'mentioned_in_note', $raw,
                        ['meta' => ['contact_note_id' => $note->target_id]]);
                }
            }
            $counts = ['create' => [], 'reuse' => [], 'skip' => []];
            foreach ($ops as $op) {
                $action = $op['registered'] ? 'skip' : ($op['reuse'] ? 'reuse' : 'create');
                $counts[$action][$op['kind']] = ($counts[$action][$op['kind']] ?? 0) + 1;
            }
            if ($commit) {
                $this->persist($scope, $ops, $counts);
            }

            return ['dry_run' => ! $commit, 'planned' => array_sum($counts['create']), 'created' => $commit ? array_sum($counts['create']) : 0, 'counts' => $counts, 'maps' => $maps];
        });
    }

    private function operation(Scope $scope, string $kind, string $id, string $class, array $payload, array $archive, ?Model $candidate = null): array
    {
        $hash = hash('sha256', json_encode($this->canonical($archive), JSON_THROW_ON_ERROR));
        $mapping = LegacyImportRecord::where('source', self::SOURCE)->where('source_type', $kind)->where('source_id', $id)->lockForUpdate()->first();
        $targetType = match ($class) {
            Event::class => 'event', EventSection::class => 'event_section', EventType::class => 'event_type', ContentCategory::class => 'content_category', EntityLink::class => 'entity_link'
        };
        $binary = substr(Ulid::fromString(Ulid::isValid($id) ? $id : $scope->id)->toBinary(), 0, 6).substr(hash('sha256', self::SOURCE.'|'.$scope->id.'|'.$kind.'|'.$id, true), 0, 10);
        $targetId = $mapping?->target_id ?? $candidate?->id ?? Str::lower(Ulid::fromBinary($binary)->toBase32());
        $query = in_array(SoftDeletes::class, class_uses_recursive($class), true) ? $class::withTrashed() : $class::query();
        $target = $query->find($targetId);
        if ($mapping && ($mapping->scope_id !== $scope->id || $mapping->created_by !== $scope->owner_id || $mapping->target_type !== $targetType || ! hash_equals($mapping->source_hash, $hash) || ! $target)) {
            throw new LogicException('An event import mapping changed, lost its target or points outside this workspace.');
        }
        if ($target && ((! $mapping && ! $candidate) || $target->scope_id !== $scope->id || (! $target instanceof EventType && $target->created_by !== $scope->owner_id))) {
            throw new LogicException('An event target ID is occupied or belongs to another owner.');
        }

        return ['kind' => $kind, 'source_id' => $id, 'target_id' => $targetId, 'class' => $class, 'target_type' => $targetType,
            'payload' => $payload, 'archive' => $archive, 'hash' => $hash, 'registered' => $mapping !== null, 'reuse' => $target !== null];
    }

    private function link(Scope $scope, string $kind, string $id, string $sourceType, string $sourceId, string $targetType, string $targetId, string $relation, array $row, array $extra = []): array
    {
        $payload = ['source_type' => $sourceType, 'source_id' => $sourceId, 'target_type' => $targetType, 'target_id' => $targetId, 'relation' => $relation];
        $candidate = EntityLink::where('scope_id', $scope->id)->where($payload)->first();

        return $this->operation($scope, $kind, $id, EntityLink::class, [...$payload, ...Arr::only($row, ['created_at', 'updated_at']), ...$extra], $row, $candidate);
    }

    private function external(Scope $scope, string $kind, string $id, string $class): Model
    {
        $mapping = LegacyImportRecord::where('source', self::SOURCE)->where('source_type', $kind)->where('source_id', Str::lower($id))->first();
        $record = $mapping ? $class::find($mapping->target_id) : null;
        if (! $record || $mapping->scope_id !== $scope->id || $mapping->created_by !== $scope->owner_id || $record->scope_id !== $scope->id
            || $record->created_by !== $scope->owner_id || $mapping->target_type !== $record->getMorphClass() || ($record instanceof Contact && $record->visibility !== 'private')) {
            throw new LogicException('A referenced record must first be imported into this private workspace.');
        }

        return $record;
    }

    private function persist(Scope $scope, array $ops, array $counts): void
    {
        $batch = Str::lower((string) Str::ulid());
        foreach ($ops as $op) {
            if (! $op['reuse']) {
                $class = $op['class'];
                $record = new $class;
                $payload = [...$op['payload'], 'id' => $op['target_id'], 'scope_id' => $scope->id];
                if (! $record instanceof EventType) {
                    $payload['created_by'] = $scope->owner_id;
                }
                if ($record instanceof Event) {
                    $payload['parent_id'] = $payload['root_id'] = null;
                }
                $record->forceFill($payload)->saveQuietly();
            }
            if (! $op['registered']) {
                LegacyImportRecord::create(['scope_id' => $scope->id, 'created_by' => $scope->owner_id, 'batch_id' => $batch, 'source' => self::SOURCE,
                    'source_type' => $op['kind'], 'source_id' => $op['source_id'], 'target_type' => $op['target_type'], 'target_id' => $op['target_id'], 'source_hash' => $op['hash'], 'snapshot' => $op['archive']]);
            }
        }
        foreach ($ops as $op) {
            if ($op['class'] === Event::class && ! $op['reuse']) {
                Event::findOrFail($op['target_id'])->forceFill(Arr::only($op['payload'], ['parent_id', 'root_id', 'updated_at']))->saveQuietly();
            }
        }
        if (array_sum($counts['create']) + array_sum($counts['reuse']) > 0) {
            ActivityLog::create(['scope_id' => $scope->id, 'actor_id' => $scope->owner_id, 'subject_type' => 'scope', 'subject_id' => $scope->id,
                'action' => 'eventor.imported', 'after' => $counts, 'context' => ['source' => self::SOURCE, 'batch_id' => $batch]]);
        }
    }

    private function validateSnapshot(array $snapshot): array
    {
        $header = ['schema' => ['required', 'in:1'], 'source' => ['required', 'in:'.self::SOURCE], 'owner_id' => ['required', 'ulid'], 'owner_hash' => ['required', 'regex:/^[a-f0-9]{64}$/']];
        foreach (self::TABLES as $table) {
            $header[$table] = ['present', 'array', 'max:'.(in_array($table, ['media', 'embeds', 'starred'], true) ? 0 : 10000)];
        }
        if (Validator::make($snapshot, $header)->fails()) {
            throw new LogicException('Invalid Eventor snapshot header, or attachments/starred need a separate migration.');
        }
        $rows = [];
        foreach (self::TABLES as $kind) {
            $rows[$kind] = [];
            foreach ($snapshot[$kind] as $row) {
                if (! is_array($row)) {
                    throw new LogicException('Invalid Eventor snapshot row.');
                }
                $identity = ['required', 'regex:/^[A-Za-z0-9_.-]{1,26}$/'];
                $rules = $kind === 'event_tags' ? ['event_id' => $identity, 'tag_id' => ['required', 'regex:/^[A-Za-z0-9]{1,64}$/']]
                    : ['id' => $identity, 'user_id' => ['required', 'ulid'], 'created_at' => ['required', 'date'], 'updated_at' => ['required', 'date']];
                if (in_array($kind, ['sections', 'categories', 'types'], true)) {
                    $rules += ['name' => ['required', 'string', 'max:'.($kind === 'types' ? 64 : 96)], 'description' => ['nullable', 'string', 'max:255'],
                        'color' => ['nullable', 'regex:/^#?([a-f0-9]{3}|[a-f0-9]{4}|[a-f0-9]{6}|[a-f0-9]{8})$/i'], 'bgcolor' => ['nullable', 'regex:/^#?([a-f0-9]{3}|[a-f0-9]{4}|[a-f0-9]{6}|[a-f0-9]{8})$/i'],
                        'sort_order' => ['required', 'integer', 'min:0'], 'is_archived' => ['required', 'boolean']];
                    if ($kind === 'types') {
                        $rules['created_at'] = ['nullable', 'date'];
                    }
                    if ($kind === 'types' && ($row['user_id'] ?? '') === 'SYSTEM00000000000000000000') {
                        $rules['id'] = ['required', 'regex:/^SYS[A-Z0-9]{23}$/'];
                        $rules['user_id'] = ['required', 'in:SYSTEM00000000000000000000'];
                    }
                }
                if ($kind === 'events') {
                    $rules += ['name' => ['nullable', 'string', 'max:255'], 'content' => ['nullable', 'string', 'max:2000000'], 'format' => ['required', 'in:0,1,2,3'],
                        'status' => ['required', 'in:0,1,2'], 'occurred_at' => ['required', 'date'], 'sort_order' => ['required', 'integer', 'min:0'],
                        'relation_type' => ['required', 'integer', 'between:0,9'], 'language' => ['nullable', 'string', 'max:16'], 'code_language' => ['nullable', 'string', 'max:32'], 'location' => ['nullable', 'string', 'max:255']];
                    foreach (['section_id', 'category_id', 'type_id', 'project_id', 'thing_id', 'exploiter_event_id', 'parent_id', 'root_id'] as $key) {
                        $rules[$key] = ['nullable', 'regex:/^[A-Za-z0-9_.-]{1,26}$/'];
                    }
                    $rules['type_id'] = ['nullable', 'regex:/^[A-Za-z0-9]{26}$/'];
                    foreach (['is_locked', 'is_pinned', 'is_blurred', 'is_expert'] as $key) {
                        $rules[$key] = ['required', 'boolean'];
                    }
                } elseif ($kind === 'contents') {
                    $rules += ['source_module' => ['required', 'in:eventor'], 'source_id' => $identity, 'field' => ['required', 'string'], 'kind' => ['required', 'string'],
                        'body_md' => ['nullable', 'string', 'max:2000000'], 'is_primary' => ['required', 'boolean'], 'deleted_at' => ['nullable', 'in:']];
                } elseif ($kind === 'tags') {
                    $rules['user_id'] = ['nullable', 'ulid'];
                    $rules['id'] = ['required', 'regex:/^[A-Za-z0-9]{1,64}$/'];
                    $rules['created_at'] = ['nullable', 'date'];
                    $rules['updated_at'] = ['nullable', 'date'];
                    $rules += ['name' => ['required', 'string', 'max:96'], 'slug' => ['required', 'string', 'max:96']];
                } elseif ($kind === 'contacts') {
                    $rules += ['event_id' => $identity, 'contact_id' => ['required', 'ulid'], 'role' => ['nullable', 'string', 'max:32'], 'note' => ['nullable', 'string', 'max:100000']];
                } elseif ($kind === 'task_refs') {
                    $rules['eventor_event_id'] = $identity;
                }
                $validator = Validator::make($row, $rules);
                if ($validator->fails()) {
                    throw new LogicException('Invalid '.$kind.' fields: '.implode(', ', array_keys($validator->failed())).'.');
                }
                if ($kind !== 'event_tags' && ! ($kind === 'tags' && empty($row['user_id']))
                    && ! ($kind === 'types' && $row['user_id'] === 'SYSTEM00000000000000000000') && Str::lower($row['user_id']) !== Str::lower($snapshot['owner_id'])) {
                    throw new LogicException('Mixed Eventor source owners.');
                }
                $id = $kind === 'event_tags' ? Str::lower($row['event_id'].'|'.$row['tag_id']) : Str::lower($row['id']);
                if (isset($rows[$kind][$id])) {
                    throw new LogicException('Duplicate Eventor source identities.');
                }
                $rows[$kind][$id] = $row;
            }
        }
        foreach (['sections', 'categories', 'types'] as $kind) {
            $names = array_map(fn ($r): string => $this->normalized($r['name']), $rows[$kind]);
            if (count($names) !== count(array_unique($names))) {
                throw new LogicException('Duplicate source catalog names need reconciliation.');
            }
        }
        foreach ($rows['events'] as $id => $row) {
            foreach (['section_id' => 'sections', 'category_id' => 'categories', 'type_id' => 'types', 'parent_id' => 'events', 'root_id' => 'events'] as $field => $kind) {
                if (! empty($row[$field]) && ! isset($rows[$kind][Str::lower($row[$field])])) {
                    throw new LogicException('An event references an excluded catalog or parent.');
                }
            }
            $seen = [$id => true];
            $parent = $row['parent_id'] ?? null;
            while ($parent) {
                $parent = Str::lower($parent);
                if (isset($seen[$parent])) {
                    throw new LogicException('Cyclic event hierarchy.');
                }
                $seen[$parent] = true;
                $parent = $rows['events'][$parent]['parent_id'] ?? null;
            }
        }
        foreach (['contents' => 'source_id', 'event_tags' => 'event_id', 'contacts' => 'event_id', 'task_refs' => 'eventor_event_id'] as $kind => $key) {
            foreach ($rows[$kind] as $row) {
                if (! isset($rows['events'][Str::lower($row[$key])]) || ($kind === 'event_tags' && ! isset($rows['tags'][Str::lower($row['tag_id'])]))) {
                    throw new LogicException('An event child row references an excluded record.');
                }
            }
        }

        return $rows;
    }

    private function decode(mixed $value): ?array
    {
        return $value === null ? null : (is_array($value) ? $value : json_decode($value, true, 512, JSON_THROW_ON_ERROR));
    }

    private function color(?string $value): ?string
    {
        if (! $value) {
            return null;
        }
        $hex = ltrim($value, '#');
        if (strlen($hex) <= 4) {
            $hex = implode('', array_map(fn ($character): string => $character.$character, str_split($hex)));
        }

        return '#'.$hex;
    }

    private function normalized(string $value): string
    {
        return Str::lower(Str::squish($value));
    }

    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => is_array($item) ? $this->canonical($item) : $item, $value);
    }
}
