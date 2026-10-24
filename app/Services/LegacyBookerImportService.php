<?php

namespace App\Services;

use App\Models\Book;
use App\Models\BookBlock;
use App\Models\BookBlockGroup;
use App\Models\BookPage;
use App\Models\BookSpace;
use App\Models\LegacyImportRecord;
use App\Models\Scope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\Uid\Ulid;

class LegacyBookerImportService
{
    private const SOURCE = 'home.zuratax.com';

    private const MODELS = ['spaces' => BookSpace::class, 'books' => Book::class, 'pages' => BookPage::class, 'groups' => BookBlockGroup::class, 'blocks' => BookBlock::class];

    private const KINDS = ['spaces' => 'booker_space', 'books' => 'booker_book', 'pages' => 'booker_page', 'groups' => 'booker_group', 'blocks' => 'booker_block'];

    public function import(Scope $scope, array $snapshot, bool $commit = false): array
    {
        $rows = $this->validate($snapshot);

        return DB::transaction(function () use ($scope, $snapshot, $rows, $commit): array {
            $scope = Scope::query()->lockForUpdate()->findOrFail($scope->id);
            $owner = User::query()->lockForUpdate()->findOrFail($scope->owner_id);
            if (! $scope->is_private || ! $scope->is_active || ! $owner->is_active || $owner->status !== 'active'
                || ! $scope->moduleEnabled('booker') || ! hash_equals($snapshot['owner_hash'], hash('sha256', Str::lower(trim((string) $owner->email))))) {
                throw new LogicException('A matching active owner and private Booker workspace are required.');
            }
            $maps = array_fill_keys(array_keys(self::MODELS), []);
            $ops = [];
            $counts = ['create' => [], 'reuse' => [], 'skip' => []];
            foreach (self::MODELS as $type => $model) {
                foreach ($rows[$type] as $id => $row) {
                    $kind = self::KINDS[$type];
                    $mapping = LegacyImportRecord::where('source', self::SOURCE)->where('source_type', $kind)->where('source_id', $id)->lockForUpdate()->first();
                    $payload = $this->payload($type, $row, $maps);
                    $candidate = $mapping ? $model::withTrashed()->find($mapping->target_id) : $this->candidate($scope, $type, $id, $row, $payload);
                    $hash = hash('sha256', json_encode($this->canonical($row), JSON_THROW_ON_ERROR));
                    $targetType = ['spaces' => 'book_space', 'books' => 'book', 'pages' => 'book_page', 'groups' => 'book_block_group', 'blocks' => 'book_block'][$type];
                    if ($mapping && ($mapping->scope_id !== $scope->id || $mapping->created_by !== $owner->id || $mapping->target_type !== $targetType || ! hash_equals($mapping->source_hash, $hash) || ! $candidate)) {
                        throw new LogicException('A book import mapping changed or its target is missing.');
                    }
                    $targetId = $mapping?->target_id ?? $candidate?->id ?? $this->targetId($scope, $kind, $id);
                    if (! $candidate && $model::withTrashed()->whereKey($targetId)->exists()) {
                        throw new LogicException('A generated book target ID is already occupied.');
                    }
                    if ($candidate) {
                        if ($this->scopeId($candidate) !== $scope->id || $candidate->created_by !== $owner->id) {
                            throw new LogicException('A reused book record belongs to another scope or creator.');
                        }
                        $parentField = ['pages' => 'book_id', 'groups' => 'page_id', 'blocks' => 'group_id'][$type] ?? null;
                        if ($parentField && $payload[$parentField] !== $candidate->$parentField) {
                            throw new LogicException('A reused book record has a conflicting container.');
                        }
                        if (($candidate instanceof Book || $candidate instanceof BookSpace) && $candidate->trashed()) {
                            throw new LogicException('Deleted books or sections must be reviewed, not restored by an import.');
                        }
                        if ($candidate instanceof BookPage && $candidate->editing_by !== null) {
                            throw new LogicException('A matching page is currently being edited.');
                        }
                    }
                    $maps[$type][$id] = $targetId;
                    $action = $mapping ? 'skip' : ($candidate ? 'reuse' : 'create');
                    $counts[$action][$type] = ($counts[$action][$type] ?? 0) + 1;
                    $ops[] = ['type' => $type, 'kind' => $kind, 'source_id' => $id, 'target_id' => $targetId,
                        'model' => $model, 'payload' => $payload, 'row' => $row, 'hash' => $hash, 'reuse' => $candidate !== null, 'registered' => $mapping !== null];
                }
            }
            if ($commit) {
                $this->persist($scope, $ops, $maps);
            }

            return ['dry_run' => ! $commit, 'counts' => $counts, 'planned' => array_sum($counts['create']),
                'created' => $commit ? array_sum($counts['create']) : 0, 'maps' => $maps];
        });
    }

    private function persist(Scope $scope, array $ops, array $maps): void
    {
        $batch = (string) Str::ulid();
        foreach ($ops as $op) {
            if (! $op['reuse']) {
                $model = $op['model'];
                $record = new $model;
                $payload = [...$op['payload'], 'id' => $op['target_id'], 'created_by' => $scope->owner_id];
                if (in_array($op['type'], ['spaces', 'books'], true)) {
                    $payload['scope_id'] = $scope->id;
                }
                $record->forceFill($payload)->saveQuietly();
            }
            if (! $op['registered']) {
                LegacyImportRecord::create(['scope_id' => $scope->id, 'created_by' => $scope->owner_id, 'batch_id' => $batch,
                    'source' => self::SOURCE, 'source_type' => $op['kind'], 'source_id' => $op['source_id'],
                    'target_type' => ['spaces' => 'book_space', 'books' => 'book', 'pages' => 'book_page', 'groups' => 'book_block_group', 'blocks' => 'book_block'][$op['type']],
                    'target_id' => $op['target_id'], 'source_hash' => $op['hash'], 'snapshot' => $op['row']]);
            }
        }
        foreach ($ops as $op) {
            if ($op['reuse']) {
                continue;
            }
            if ($op['type'] === 'pages' && ! empty($op['row']['parent_id'])) {
                BookPage::findOrFail($op['target_id'])->forceFill(['parent_id' => $maps['pages'][Str::lower($op['row']['parent_id'])], 'updated_at' => $op['row']['updated_at']])->saveQuietly();
            }
            if ($op['type'] === 'groups') {
                BookBlockGroup::findOrFail($op['target_id'])->forceFill(['master_block_id' => $maps['blocks'][Str::lower($op['row']['master_block_id'])], 'updated_at' => $op['row']['updated_at']])->saveQuietly();
            }
        }
    }

    private function payload(string $type, array $row, array $maps): array
    {
        $payload = Arr::only($row, match ($type) {
            'spaces' => ['title', 'slug', 'sort_order', 'is_archived', 'created_at', 'updated_at'],
            'books' => ['title', 'slug', 'description', 'structure_mode', 'cover_color', 'cover_svg_url', 'cover_svg_text', 'sort_order', 'is_archived', 'created_at', 'updated_at'],
            'pages' => ['title', 'slug', 'sort_order', 'is_archived', 'created_at', 'updated_at'],
            'groups' => ['type', 'role', 'is_hidden_by_default', 'sort_order', 'created_at', 'updated_at'],
            'blocks' => ['version_number', 'title', 'content', 'status', 'published_at', 'created_at', 'updated_at'],
        });
        if ($type !== 'blocks') {
            $payload['visibility'] = 'private';
            $payload['meta'] = [...($this->json($row['meta'] ?? null) ?? []), 'import' => ['source' => self::SOURCE, 'external_id' => Str::lower($row['id'])]];
        }
        if ($type === 'books') {
            $payload += ['kind' => 'book', 'space_id' => empty($row['space_id']) ? null : $maps['spaces'][Str::lower($row['space_id'])],
                'project_id' => null, 'export_settings' => $this->json($row['export_settings'] ?? null)];
        } elseif ($type === 'pages') {
            $payload += ['book_id' => $maps['books'][Str::lower($row['book_id'])], 'parent_id' => null, 'editing_by' => null, 'editing_started_at' => null];
        } elseif ($type === 'groups') {
            $payload += ['page_id' => $maps['pages'][Str::lower($row['page_id'])], 'master_block_id' => null];
        } elseif ($type === 'blocks') {
            $payload += ['group_id' => $maps['groups'][Str::lower($row['group_id'])], 'payload' => $this->json($row['payload'] ?? null)];
            $payload['search_text'] = trim(implode("\n", array_filter([$row['title'] ?? null, $row['content'] ?? null, $payload['payload'] ? json_encode($payload['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : null])));
        }

        return $payload;
    }

    private function candidate(Scope $scope, string $type, string $id, array $row, array $payload): ?Model
    {
        $model = self::MODELS[$type];
        $parent = ['spaces' => 'scope_id', 'books' => 'scope_id', 'pages' => 'book_id', 'groups' => 'page_id', 'blocks' => 'group_id'][$type];
        $query = $model::withTrashed()->where($parent, $payload[$parent] ?? $scope->id);
        $records = $query->limit(10001)->get();
        if ($records->count() > 10000) {
            throw new LogicException('A book container is too large for this bounded reconciliation.');
        }
        $matches = $records->filter(function (Model $record) use ($type, $id, $row): bool {
            if ($record->id === $id || Str::lower((string) ($record->meta['import']['external_id'] ?? '')) === $id) {
                return true;
            }
            if ($type === 'blocks') {
                return (int) $record->version_number === (int) $row['version_number'];
            }

            return $type !== 'groups' && ($this->normalized($record->title) === $this->normalized($row['title']) || (! empty($row['slug']) && $record->slug === $row['slug']));
        });
        if ($matches->count() > 1) {
            throw new LogicException('Ambiguous existing '.$type.' records.');
        }
        $record = $matches->first();
        if ($record && $record->id !== $id && Str::lower((string) ($record->meta['import']['external_id'] ?? '')) !== $id) {
            $same = $record->getRawOriginal('created_at') === $row['created_at'];
            $same = $same && ($type === 'blocks'
                ? ($record->content === ($row['content'] ?? null) && $record->payload === $this->json($row['payload'] ?? null))
                : $this->normalized($record->title) === $this->normalized($row['title']));
            if (! $same) {
                throw new LogicException('A possible duplicate '.$type.' record lacks reliable provenance.');
            }
        }
        if ($type === 'groups' && ! $record && $records->isNotEmpty()) {
            // Similar paragraphs are not reliable identities, even at the same position.
            throw new LogicException('Existing page blocks cannot be merged without source identities.');
        }

        return $record;
    }

    private function scopeId(Model $record): ?string
    {
        if ($record instanceof Book || $record instanceof BookSpace) {
            return $record->scope_id;
        }
        if ($record instanceof BookPage) {
            return Book::withTrashed()->find($record->book_id)?->scope_id;
        }
        if ($record instanceof BookBlockGroup) {
            $page = BookPage::withTrashed()->find($record->page_id);

            return $page ? $this->scopeId($page) : null;
        }
        $group = BookBlockGroup::withTrashed()->find($record->group_id);

        return $group ? $this->scopeId($group) : null;
    }

    private function validate(array $snapshot): array
    {
        $rules = ['schema' => ['required', 'in:1'], 'source' => ['required', 'in:'.self::SOURCE], 'owner_id' => ['required', 'ulid'], 'owner_hash' => ['required', 'regex:/^[a-f0-9]{64}$/']];
        foreach (['spaces' => 1000, 'books' => 1000, 'pages' => 10000, 'groups' => 20000, 'blocks' => 40000] as $type => $max) {
            $rules[$type] = ['present', 'array', 'max:'.$max];
        }
        if (Validator::make($snapshot, $rules)->fails()) {
            throw new LogicException('Invalid book snapshot header.');
        }
        $rows = [];
        foreach (self::MODELS as $type => $model) {
            $rows[$type] = [];
            foreach ($snapshot[$type] as $row) {
                $rules = ['id' => ['required', 'ulid'], 'user_id' => ['required', 'ulid'], 'deleted_at' => ['nullable', 'in:'], 'created_at' => ['required', 'date'], 'updated_at' => ['required', 'date']];
                if (in_array($type, ['spaces', 'books', 'pages'], true)) {
                    $rules += ['title' => ['required', 'string', 'max:255'], 'slug' => ['nullable', 'string', 'max:160'], 'sort_order' => ['required', 'integer']];
                }
                if ($type === 'books') {
                    $rules += ['structure_mode' => ['required', 'in:tree,flat'], 'description' => ['nullable', 'string'], 'cover_svg_text' => ['nullable', 'string'], 'space_id' => ['nullable', 'ulid']];
                } elseif ($type === 'pages') {
                    $rules += ['book_id' => ['required', 'ulid'], 'parent_id' => ['nullable', 'ulid']];
                } elseif ($type === 'groups') {
                    $rules += ['page_id' => ['required', 'ulid'], 'master_block_id' => ['required', 'ulid'], 'role' => ['required', 'string', 'max:32'], 'sort_order' => ['required', 'integer'], 'type' => ['required', 'in:markdown,excalidraw,svg,photo,table,code,callout,checklist,divider,embed']];
                } elseif ($type === 'blocks') {
                    $rules += ['group_id' => ['required', 'ulid'], 'version_number' => ['required', 'integer', 'min:1'], 'title' => ['nullable', 'string'], 'content' => ['nullable', 'string'], 'status' => ['required', 'in:draft,published,archived'], 'published_at' => ['nullable', 'date']];
                }
                if (! is_array($row) || Validator::make($row, $rules)->fails() || Str::lower($row['user_id']) !== Str::lower($snapshot['owner_id'])) {
                    throw new LogicException('Invalid '.$type.' row or a mixed source owner.');
                }
                $id = Str::lower($row['id']);
                if (isset($rows[$type][$id])) {
                    throw new LogicException('Duplicate book source IDs.');
                }
                foreach (['meta', 'payload', 'export_settings'] as $json) {
                    if (isset($row[$json]) && $this->json($row[$json]) !== null && ! is_array($this->json($row[$json]))) {
                        throw new LogicException('A source JSON field must be an object or array.');
                    }
                }
                $rows[$type][$id] = $row;
            }
        }
        $slugs = [];
        foreach (['spaces', 'books', 'pages'] as $type) {
            foreach ($rows[$type] as $row) {
                if (! empty($row['slug'])) {
                    $key = $type.':'.($type === 'pages' ? Str::lower($row['book_id']) : '').':'.Str::lower($row['slug']);
                    if (isset($slugs[$key])) {
                        throw new LogicException('Duplicate source slugs in the same book container.');
                    }
                    $slugs[$key] = true;
                }
            }
        }
        foreach ($rows['books'] as $row) {
            if (! empty($row['space_id']) && ! isset($rows['spaces'][Str::lower($row['space_id'])])) {
                throw new LogicException('A book references a missing section.');
            }
        }
        foreach ($rows['pages'] as $id => $row) {
            if (! isset($rows['books'][Str::lower($row['book_id'])])) {
                throw new LogicException('A page references a missing book.');
            }
            $seen = [$id => true];
            $parent = $row['parent_id'] ?? null;
            while ($parent) {
                $parent = Str::lower($parent);
                if (! isset($rows['pages'][$parent]) || isset($seen[$parent]) || Str::lower($rows['pages'][$parent]['book_id']) !== Str::lower($row['book_id'])) {
                    throw new LogicException('Invalid, cross-book, or cyclic page hierarchy.');
                }
                $seen[$parent] = true;
                $parent = $rows['pages'][$parent]['parent_id'] ?? null;
            }
        }
        foreach ($rows['groups'] as $id => $row) {
            $master = $rows['blocks'][Str::lower($row['master_block_id'])] ?? null;
            if (! isset($rows['pages'][Str::lower($row['page_id'])]) || ! $master || Str::lower($master['group_id']) !== $id) {
                throw new LogicException('A block group has a missing page or an invalid master version.');
            }
        }
        $versionKeys = [];
        foreach ($rows['blocks'] as $row) {
            $groupId = Str::lower($row['group_id']);
            $key = $groupId.':'.$row['version_number'];
            if (! isset($rows['groups'][$groupId]) || isset($versionKeys[$key])) {
                throw new LogicException('Missing block group or duplicate version number.');
            }
            $versionKeys[$key] = true;
        }

        return $rows;
    }

    private function targetId(Scope $scope, string $kind, string $id): string
    {
        $bytes = substr(Ulid::fromString($id)->toBinary(), 0, 6).substr(hash('sha256', self::SOURCE.'|'.$scope->id.'|'.$kind.'|'.$id, true), 0, 10);

        return Str::lower(Ulid::fromBinary($bytes)->toBase32());
    }

    private function normalized(?string $value): string
    {
        return Str::lower(Str::squish($value ?? ''));
    }

    private function json(mixed $value): mixed
    {
        return is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $value;
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
