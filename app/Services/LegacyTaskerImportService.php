<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Comment;
use App\Models\Contact;
use App\Models\EntityLink;
use App\Models\LegacyImportRecord;
use App\Models\Project;
use App\Models\Scope;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\Uid\Ulid;

class LegacyTaskerImportService
{
    private const SOURCE = 'home.zuratax.com';

    private const STATUSES = [20 => 'scheduled', 21 => 'in_progress', 22 => 'done', 23 => 'blocked', 24 => 'cancelled'];

    private const PRIORITIES = [11 => 5, 12 => 4, 13 => 2, 14 => 1, 15 => 1];

    public function __construct(private readonly TaskKeyService $keys) {}

    public function import(Scope $scope, array $snapshot, bool $commit = false): array
    {
        $rows = $this->validateSnapshot($snapshot);

        return DB::transaction(function () use ($scope, $snapshot, $rows, $commit): array {
            $scope = Scope::query()->lockForUpdate()->findOrFail($scope->id);
            $owner = User::query()->lockForUpdate()->findOrFail($scope->owner_id);
            if (! $scope->is_private || ! $scope->is_active || ! $owner->is_active || $owner->status !== 'active'
                || ! $scope->moduleEnabled('tasker') || ! hash_equals($snapshot['owner_hash'], hash('sha256', Str::lower(trim((string) $owner->email))))) {
                throw new LogicException('A matching active owner and private Tasker workspace are required.');
            }
            $projects = Project::withTrashed()->where('scope_id', $scope->id)->limit(10001)->get();
            $tasks = Task::withTrashed()->where('scope_id', $scope->id)->limit(10001)->get();
            if ($projects->count() > 10000 || $tasks->count() > 10000) {
                throw new LogicException('This migration requires a bounded workspace reconciliation.');
            }
            $ops = [];
            $projectMap = [];
            foreach ($rows['projects'] as $id => $row) {
                $key = Str::upper(trim($row['code']));
                $mapped = $this->mapped('tasker_project', $id, Project::class);
                $candidates = $mapped ? collect([$mapped]) : $projects->filter(fn (Project $p): bool => $p->id === $id || $p->key === $key || $this->normalized($p->title) === $this->normalized($row['title']));
                if ($candidates->count() > 1) {
                    throw new LogicException('Ambiguous project matches.');
                }
                $candidate = $candidates->first();
                if ($candidate && ($candidate->trashed() || (! $mapped && ($candidate->key !== $key || $this->normalized($candidate->title) !== $this->normalized($row['title']))))) {
                    throw new LogicException('A project code/title conflicts with an existing or deleted project.');
                }
                $payload = [...Arr::only($row, ['title', 'description', 'result', 'color', 'started_on', 'is_pinned', 'sort_order', 'created_at', 'updated_at']),
                    'key' => $key, 'status' => [20 => 'planning', 21 => 'active', 22 => 'completed', 23 => 'on_hold', 24 => 'archived'][(int) $row['status_id']],
                    'priority' => self::PRIORITIES[(int) $row['priority_id']], 'visibility' => 'private', 'include_in_reports' => false,
                    'show_in_tasker' => (bool) ($row['show_in_tasker'] ?? true), 'due_on' => $row['due_at'] ?? null, 'completed_at' => $row['closed_at'] ?? null,
                    'meta' => ['import' => ['source' => self::SOURCE, 'external_id' => $id]]];
                $archive = ['row' => $row, 'legacy_blockers' => $rows['blockers']];
                $ops[] = $op = $this->operation($scope, 'tasker_project', $id, Project::class, 'project', $payload, $archive, $candidate);
                $projectMap[$id] = $op['target_id'];
            }
            $taskMap = [];
            $taskOpIndexes = [];
            foreach ($rows['tasks'] as $id => $row) {
                $projectId = empty($row['project_id']) ? null : $projectMap[Str::lower($row['project_id'])];
                $mapped = $this->mapped('tasker_task', $id, Task::class);
                $candidates = $mapped ? collect([$mapped]) : $tasks->filter(fn (Task $t): bool => $t->id === $id || ($t->project_id === $projectId && $this->normalized($t->title) === $this->normalized($row['title'])));
                if ($candidates->count() > 1) {
                    throw new LogicException('Ambiguous task matches.');
                }
                $candidate = $candidates->first();
                if (! $mapped && $candidate && ($candidate->project_id !== $projectId || $this->normalized($candidate->title) !== $this->normalized($row['title'])
                    || ($candidate->getRawOriginal('created_at') !== $row['created_at'] && Str::lower((string) ($candidate->meta['import']['external_id'] ?? '')) !== $id))) {
                    throw new LogicException('A task matches by title/ID but its provenance is uncertain.');
                }
                $spans = array_values(array_filter($rows['spans'], fn (array $s): bool => Str::lower($s['task_id']) === $id));
                $payload = [...Arr::only($row, ['title', 'description', 'result', 'due_at', 'tracked_seconds', 'sort_order', 'is_pinned', 'created_at', 'updated_at']),
                    'project_id' => $projectId, 'parent_id' => null, 'assignee_id' => null,
                    'status' => self::STATUSES[(int) $row['status_id']], 'priority' => self::PRIORITIES[(int) $row['priority_id']],
                    'completed_at' => in_array((int) $row['status_id'], [22, 24], true) ? ($row['closed_at'] ?? $row['updated_at']) : null,
                    'meta' => ['import' => ['source' => self::SOURCE, 'external_id' => $id], 'legacy_spans' => $spans]];
                $ops[] = $op = $this->operation($scope, 'tasker_task', $id, Task::class, 'task', $payload, ['row' => $row, 'spans' => $spans], $candidate);
                $taskOpIndexes[$id] = array_key_last($ops);
                $taskMap[$id] = $op['target_id'];
            }
            foreach ($rows['tasks'] as $id => $row) {
                if (! empty($row['parent_task_id'])) {
                    $parent = $taskMap[Str::lower($row['parent_task_id'])];
                    $index = $taskOpIndexes[$id];
                    if ($ops[$index]['reuse']) {
                        $existing = Task::withTrashed()->findOrFail($ops[$index]['target_id']);
                        if ($existing->parent_id !== $parent) {
                            throw new LogicException('A reused task has a conflicting parent.');
                        }
                    }
                    $ops[$index]['payload']['parent_id'] = $parent;
                }
                if (! empty($row['assignee_contact_id'])) {
                    $contact = $this->contact($scope, Str::lower($row['assignee_contact_id']));
                    $ops[] = $this->operation($scope, 'tasker_assignee_link', $id, EntityLink::class, 'entity_link', [
                        'source_type' => 'task', 'source_id' => $taskMap[$id], 'target_type' => 'contact', 'target_id' => $contact->id,
                        'relation' => 'legacy_assignee', 'note' => 'Исполнитель-контакт из прежней системы; это не учётная запись.',
                        ...Arr::only($row, ['created_at', 'updated_at']),
                    ], $row);
                }
            }
            foreach ($rows['checklist_items'] as $id => $row) {
                $taskId = $taskMap[Str::lower($row['task_id'])];
                $mapped = $this->mapped('tasker_checklist', $id, TaskChecklistItem::class);
                $matches = $mapped ? collect([$mapped]) : TaskChecklistItem::where('task_id', $taskId)->get()->filter(fn ($t): bool => $t->id === $id || ($t->title === $row['title'] && $t->getRawOriginal('created_at') === $row['created_at']));
                if ($matches->count() > 1) {
                    throw new LogicException('Ambiguous checklist matches.');
                }
                $done = in_array((int) $row['status_id'], [22, 24], true);
                $ops[] = $this->operation($scope, 'tasker_checklist', $id, TaskChecklistItem::class, 'task_checklist_item', [
                    'task_id' => $taskId, ...Arr::only($row, ['title', 'sort_order', 'created_at', 'updated_at']),
                    'completed_by' => $done ? $owner->id : null, 'completed_at' => $done ? $row['updated_at'] : null,
                    'meta' => ['import' => ['source' => self::SOURCE, 'external_id' => $id], 'completion_time_is_legacy_fallback' => $done],
                ], $row, $matches->first());
            }
            foreach ($rows['logs'] as $id => $row) {
                $taskId = $taskMap[Str::lower($row['task_id'])];
                if ($row['kind'] === 'status_change') {
                    $meta = $this->decode($row['meta'] ?? null);
                    if (! isset(self::STATUSES[$meta['from'] ?? 0], self::STATUSES[$meta['to'] ?? 0])) {
                        throw new LogicException('An old status history entry has unknown states.');
                    }
                    $candidate = ActivityLog::where('subject_type', 'task')->where('subject_id', $taskId)->whereKey($id)->first();
                    $ops[] = $this->operation($scope, 'tasker_activity', $id, ActivityLog::class, 'activity_log', [
                        'subject_type' => 'task', 'subject_id' => $taskId, 'actor_id' => $owner->id, 'action' => 'task.updated',
                        'before' => ['status' => self::STATUSES[$meta['from']]], 'after' => ['status' => self::STATUSES[$meta['to']]],
                        'context' => ['import' => ['source' => self::SOURCE, 'external_id' => $id], 'occurred_at' => $row['occurred_at'] ?? null],
                        'created_at' => $row['occurred_at'] ?? $row['created_at'],
                    ], $row, $candidate);
                } else {
                    $candidate = Comment::withTrashed()->where('commentable_type', 'task')->where('commentable_id', $taskId)->whereKey($id)->first();
                    $ops[] = $this->operation($scope, 'tasker_comment', $id, Comment::class, 'comment', [
                        'commentable_type' => 'task', 'commentable_id' => $taskId, 'kind' => 'comment',
                        'content' => ($row['kind'] === 'report' ? "**Отчёт из Teftele**\n\n" : '').($row['content'] ?? ''),
                        ...Arr::only($row, ['created_at', 'updated_at']),
                    ], $row, $candidate);
                }
            }
            foreach (LegacyImportRecord::where('scope_id', $scope->id)->where('source', self::SOURCE)->where('source_type', 'contact_note')->get() as $note) {
                $raw = $note->snapshot;
                $sourceTask = Str::lower((string) ($raw['tasker_task_id'] ?? ''));
                if (isset($taskMap[$sourceTask])) {
                    $contact = $this->contact($scope, Str::lower($raw['contact_id']));
                    $ops[] = $this->operation($scope, 'tasker_contact_mention', $note->source_id, EntityLink::class, 'entity_link', [
                        'source_type' => 'contact', 'source_id' => $contact->id, 'target_type' => 'task', 'target_id' => $taskMap[$sourceTask],
                        'relation' => 'mentioned_in_note', 'meta' => ['contact_note_id' => $note->target_id],
                        ...Arr::only($raw, ['created_at', 'updated_at']),
                    ], $raw);
                }
            }
            $counts = ['create' => [], 'reuse' => [], 'skip' => []];
            foreach ($ops as $op) {
                $action = $op['registered'] ? 'skip' : ($op['reuse'] ? 'reuse' : 'create');
                $counts[$action][$op['kind']] = ($counts[$action][$op['kind']] ?? 0) + 1;
            }
            if ($commit) {
                $this->persist($scope, $ops);
            }

            return ['dry_run' => ! $commit, 'counts' => $counts, 'planned' => array_sum($counts['create']),
                'created' => $commit ? array_sum($counts['create']) : 0, 'project_map' => $projectMap, 'task_map' => $taskMap];
        });
    }

    private function persist(Scope $scope, array $ops): void
    {
        $batch = (string) Str::ulid();
        foreach ($ops as $op) {
            if (! $op['reuse']) {
                $class = $op['model'];
                $record = new $class;
                $payload = [...$op['payload'], 'id' => $op['target_id']];
                if ($record instanceof Task) {
                    $project = $payload['project_id'] ? Project::findOrFail($payload['project_id']) : null;
                    $payload = [...$payload, ...$this->keys->reserve($scope, $project), 'parent_id' => null];
                    $payload['department_id'] = $project?->department_id;
                }
                if (! $record instanceof ActivityLog) {
                    $payload['created_by'] = $scope->owner_id;
                }
                if (! $record instanceof TaskChecklistItem) {
                    $payload['scope_id'] = $scope->id;
                }
                $record->forceFill($payload)->saveQuietly();
            }
            if (! $op['registered']) {
                LegacyImportRecord::create(['scope_id' => $scope->id, 'created_by' => $scope->owner_id, 'batch_id' => $batch,
                    'source' => self::SOURCE, 'source_type' => $op['kind'], 'source_id' => $op['source_id'],
                    'target_type' => $op['target_type'], 'target_id' => $op['target_id'], 'source_hash' => $op['hash'], 'snapshot' => $op['archive']]);
            }
        }
        foreach ($ops as $op) {
            if ($op['model'] === Task::class && ! $op['reuse'] && $op['payload']['parent_id'] !== null) {
                Task::findOrFail($op['target_id'])->forceFill(['parent_id' => $op['payload']['parent_id'], 'updated_at' => $op['payload']['updated_at']])->saveQuietly();
            }
        }
    }

    private function operation(Scope $scope, string $kind, string $sourceId, string $model, string $targetType, array $payload, array $archive, ?Model $candidate = null): array
    {
        $hash = hash('sha256', json_encode($this->canonical($archive), JSON_THROW_ON_ERROR));
        $mapping = LegacyImportRecord::where('source', self::SOURCE)->where('source_type', $kind)->where('source_id', $sourceId)->lockForUpdate()->first();
        $query = in_array(SoftDeletes::class, class_uses_recursive($model), true) ? $model::withTrashed() : $model::query();
        $binary = substr(Ulid::fromString($sourceId)->toBinary(), 0, 6).substr(hash('sha256', self::SOURCE.'|'.$scope->id.'|'.$kind.'|'.$sourceId, true), 0, 10);
        $targetId = $mapping?->target_id ?? $candidate?->id ?? Str::lower(Ulid::fromBinary($binary)->toBase32());
        $target = $query->find($targetId);
        if ($mapping && ($mapping->scope_id !== $scope->id || $mapping->created_by !== $scope->owner_id || $mapping->target_type !== $targetType || ! hash_equals($mapping->source_hash, $hash) || $target === null)) {
            throw new LogicException('An import mapping changed or points outside the intended workspace.');
        }
        if ($target && ! $mapping && ! $candidate) {
            throw new LogicException('A generated target ID is already occupied.');
        }
        if ($target) {
            $targetScope = $target instanceof TaskChecklistItem ? $target->task()->withTrashed()->value('scope_id') : $target->scope_id;
            $creator = $target instanceof ActivityLog ? $target->actor_id : $target->created_by;
            if ($targetScope !== $scope->id || $creator !== $scope->owner_id) {
                throw new LogicException('A reused record belongs to a different scope or owner.');
            }
        }

        return ['kind' => $kind, 'source_id' => $sourceId, 'target_id' => $targetId, 'model' => $model, 'target_type' => $targetType,
            'payload' => $payload, 'archive' => $archive, 'hash' => $hash, 'reuse' => $target !== null, 'registered' => $mapping !== null];
    }

    private function mapped(string $kind, string $sourceId, string $model): ?Model
    {
        $mapping = LegacyImportRecord::where('source', self::SOURCE)->where('source_type', $kind)->where('source_id', $sourceId)->first();
        if (! $mapping) {
            return null;
        }
        $query = in_array(SoftDeletes::class, class_uses_recursive($model), true) ? $model::withTrashed() : $model::query();

        return $query->find($mapping->target_id);
    }

    private function contact(Scope $scope, string $sourceId): Contact
    {
        $mapping = LegacyImportRecord::where('source', self::SOURCE)->where('source_type', 'contact')->where('source_id', $sourceId)->where('scope_id', $scope->id)->first();
        $contact = $mapping ? Contact::find($mapping->target_id) : null;
        if (! $contact || $contact->scope_id !== $scope->id || $contact->created_by !== $scope->owner_id || $contact->visibility !== 'private') {
            throw new LogicException('A referenced contact must first be imported privately into this scope.');
        }

        return $contact;
    }

    private function validateSnapshot(array $snapshot): array
    {
        if (Validator::make($snapshot, ['schema' => ['required', 'in:1'], 'source' => ['required', 'in:'.self::SOURCE],
            'owner_id' => ['required', 'ulid'], 'owner_hash' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'projects' => ['present', 'array', 'max:100'], 'tasks' => ['present', 'array', 'max:1000'],
            'checklist_items' => ['present', 'array', 'max:5000'], 'logs' => ['present', 'array', 'max:5000'],
            'spans' => ['present', 'array', 'max:5000'], 'blockers' => ['present', 'array', 'max:1000'],
        ])->fails()) {
            throw new LogicException('Invalid tasker snapshot header.');
        }
        $rows = [];
        foreach (['projects', 'tasks', 'checklist_items', 'logs', 'spans', 'blockers'] as $type) {
            $rows[$type] = [];
            foreach ($snapshot[$type] as $row) {
                $rules = ['id' => ['required', 'ulid'], 'user_id' => ['required', 'ulid'], 'deleted_at' => ['nullable', 'in:'], 'created_at' => ['required', 'date'], 'updated_at' => ['required', 'date']];
                if (in_array($type, ['projects', 'tasks', 'checklist_items'], true)) {
                    $rules += ['title' => ['required', 'string', 'max:255'], 'status_id' => ['required', 'in:20,21,22,23,24'], 'sort_order' => ['required', 'integer']];
                }
                if (in_array($type, ['projects', 'tasks'], true)) {
                    $rules += ['priority_id' => ['required', 'in:11,12,13,14,15'], 'description' => ['nullable', 'string'], 'result' => ['nullable', 'string'], 'due_at' => ['nullable', 'date'], 'closed_at' => ['nullable', 'date']];
                }
                if ($type === 'projects') {
                    $rules += ['code' => ['required', 'regex:/^[A-Za-z][A-Za-z0-9]{1,9}$/'], 'color' => ['required', 'regex:/^#[A-Fa-f0-9]{6}$/'], 'started_on' => ['nullable', 'date']];
                }
                if ($type === 'tasks') {
                    $rules += ['project_id' => ['nullable', 'ulid'], 'parent_task_id' => ['nullable', 'ulid'], 'assignee_contact_id' => ['nullable', 'ulid'], 'tracked_seconds' => ['required', 'integer', 'min:0']];
                }
                if ($type === 'logs') {
                    $rules += ['kind' => ['required', 'in:note,report,status_change,blocker,clarification'], 'content' => ['nullable', 'string'], 'occurred_at' => ['nullable', 'date']];
                }
                if (! is_array($row) || Validator::make($row, $rules)->fails() || Str::lower($row['user_id']) !== Str::lower($snapshot['owner_id'])) {
                    throw new LogicException('Invalid row in '.$type.' or a mixed source owner.');
                }
                $id = Str::lower($row['id']);
                if (isset($rows[$type][$id])) {
                    throw new LogicException('Duplicate tasker source IDs.');
                }
                $rows[$type][$id] = $row;
            }
        }
        $codes = array_map(fn ($r): string => Str::upper($r['code']), $rows['projects']);
        if (count($codes) !== count(array_unique($codes))) {
            throw new LogicException('Duplicate source project codes.');
        }
        foreach ($rows['tasks'] as $id => $row) {
            if (! empty($row['project_id']) && ! isset($rows['projects'][Str::lower($row['project_id'])])) {
                throw new LogicException('A task references a project outside this snapshot.');
            }
            $seen = [$id => true];
            $parent = $row['parent_task_id'] ?? null;
            while ($parent) {
                $parent = Str::lower($parent);
                if (! isset($rows['tasks'][$parent]) || isset($seen[$parent])) {
                    throw new LogicException('Invalid or cyclic task hierarchy.');
                }
                $seen[$parent] = true;
                $parent = $rows['tasks'][$parent]['parent_task_id'] ?? null;
            }
        }
        foreach (['checklist_items', 'logs', 'spans'] as $type) {
            foreach ($rows[$type] as $row) {
                if (! is_string($row['task_id'] ?? null) || ! isset($rows['tasks'][Str::lower($row['task_id'])])) {
                    throw new LogicException('A child record references a missing source task.');
                }
            }
        }

        return $rows;
    }

    private function normalized(?string $text): string
    {
        return Str::lower(Str::squish($text ?? ''));
    }

    private function decode(mixed $value): array
    {
        return is_string($value) ? (json_decode($value, true, 512, JSON_THROW_ON_ERROR) ?? []) : ($value ?? []);
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
