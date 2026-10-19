<?php

namespace App\Services;

use App\Models\Book;
use App\Models\ContentNavigationLink;
use App\Models\Diagram;
use App\Models\FilerFile;
use App\Models\LoreEntry;
use App\Models\Scope;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

class ContentNavigationService
{
    public const TYPES = ['diagram' => Diagram::class, 'file' => FilerFile::class, 'lore' => LoreEntry::class, 'task' => Task::class, 'lightbook' => Book::class];

    public function __construct(private readonly DiagramAccessService $diagrams, private readonly FilerAccessService $files,
        private readonly ContractorAccessService $access, private readonly DarklogLinkService $targets) {}

    public function resolve(User $actor, Scope $scope, string $type, string $id, bool $write = false): Model
    {
        $class = self::TYPES[$type] ?? null;
        abort_unless($class, 404);
        $query = $class::query()->where('scope_id', $scope->id);
        if ($type === 'lightbook') {
            $query->where('kind', 'lightbook');
        }
        $record = $query->findOrFail($id);
        abort_unless($this->can($actor, $scope, $record, $write), 404);

        return $record;
    }

    public function can(User $actor, Scope $scope, Model $record, bool $write = false): bool
    {
        if ($record->scope_id !== $scope->id) {
            return false;
        }
        if ($record instanceof Diagram) {
            return $this->diagrams->can($actor, $scope, $record, $write ? 'task.update' : 'task.view');
        }
        if ($record instanceof FilerFile) {
            return $this->files->file($actor, $scope, $record, $write);
        }
        if ($record instanceof Task) {
            return $this->files->subject($actor, $scope, $record, $write);
        }
        if ($record instanceof Book && $record->kind === 'lightbook') {
            $record->loadMissing('project');

            return $this->access->canAccessBook($actor, $scope, $record)
                && (! $write || $this->access->allows($actor, $scope, 'book.update', $record->project));
        }

        return $record instanceof LoreEntry && $this->access->allows($actor, $scope, $write ? 'task.update' : 'task.view') && $this->targets->canView($actor, $scope, $record)
            && (! $write || $this->targets->projectAllowed($actor, $scope, $record->project_id, 'task.update'));
    }

    public function describe(string $type, Model $record): array
    {
        if ($record instanceof LoreEntry) {
            $record->loadMissing(['revisions' => fn (Relation $q) => $q->whereIn('status', ['active', 'scheduled'])->where('effective_from', '<=', now())
                ->where(fn (Builder $r) => $r->whereNull('effective_until')->orWhere('effective_until', '>', now()))->orderByDesc('version')]);
        }

        return ['type' => $type, 'id' => $record->id, 'title' => $record instanceof LoreEntry ? ($record->revisions->first()?->title ?? $record->code) : ($record->title ?? $record->name),
            'code' => $record->task_key ?? $record->code];
    }

    public function document(string $type, Model $record): array
    {
        return match ($type) {
            'diagram' => $record->only(['id', 'title', 'xml', 'preview_svg', 'revision']),
            'file' => $record->only(['id', 'name', 'description', 'mime', 'size']),
            'lore' => ['id' => $record->id, 'code' => $record->code, 'current_revision' => $record->revisions->first()?->only(['title', 'content', 'reason', 'version', 'effective_from'])],
            'task' => $record->only(['id', 'task_key', 'title', 'description', 'result', 'agent_notes', 'status', 'priority', 'due_at', 'completed_at']),
            'lightbook' => $this->lightbookDocument($record),
        };
    }

    public function links(User $actor, Scope $scope, string $type, string $id): array
    {
        $links = ContentNavigationLink::query()->where('scope_id', $scope->id)->where('source_type', $type)->where('source_id', $id)->oldest('id')->get();
        $targets = [];
        foreach ($links->groupBy('target_type') as $targetType => $group) {
            $class = self::TYPES[$targetType] ?? null;
            if ($class === null) {
                continue;
            }
            $query = $class::query()->select($this->metadataColumns($targetType))->where('scope_id', $scope->id)->whereIn('id', $group->pluck('target_id'));
            if ($targetType === 'lightbook') {
                $query->where('kind', 'lightbook');
            }
            if ($targetType === 'file') {
                $query->with('attachments.subject');
            }
            foreach ($query->get() as $record) {
                if ($this->can($actor, $scope, $record)) {
                    $targets[$targetType.':'.$record->id] = $this->describe($targetType, $record);
                }
            }
        }

        return $links->map(function (ContentNavigationLink $link) use ($targets): ?array {
            $target = $targets[$link->target_type.':'.$link->target_id] ?? null;

            return $target ? [...$link->only(['id', 'label', 'region', 'source_revision']), 'target' => $target] : null;
        })->filter()->values()->all();
    }

    public function candidates(User $actor, Scope $scope, string $type, string $term, int $page): array
    {
        $class = self::TYPES[$type];
        $query = $class::query()->select($this->metadataColumns($type))->where('scope_id', $scope->id)->orderByDesc('id');
        if ($type === 'lightbook') {
            $query->where('kind', 'lightbook');
        }
        if ($type === 'file') {
            $query->with('attachments.subject');
        }
        if ($term !== '') {
            $query->where(function (Builder $q) use ($type, $term): void {
                $q->where($type === 'file' ? 'name' : ($type === 'lore' ? 'code' : 'title'), 'like', '%'.$term.'%');
                if ($type === 'task') {
                    $q->orWhere('task_key', 'like', '%'.$term.'%');
                } elseif ($type === 'lore') {
                    $q->orWhereHas('revisions', fn (Builder $r) => $r->where('title', 'like', '%'.$term.'%'));
                }
            });
        }
        $items = [];
        $skip = ($page - 1) * 30;
        foreach ($query->lazy(100) as $record) {
            if (! $this->can($actor, $scope, $record)) {
                continue;
            }
            if ($skip-- > 0) {
                continue;
            }
            $items[] = $this->describe($type, $record);
            if (count($items) === 31) {
                break;
            }
        }

        return ['data' => array_slice($items, 0, 30), 'meta' => ['page' => $page, 'has_more' => count($items) > 30]];
    }

    private function lightbookDocument(Book $lightbook): array
    {
        $lightbook->loadMissing(['page.groups.masterBlock']);
        $page = $lightbook->page;

        return [
            ...$lightbook->only(['id', 'title', 'description', 'visibility', 'updated_at']),
            'page' => $page ? [
                ...$page->only(['id', 'title', 'updated_at']),
                'groups' => $page->groups->sortBy('sort_order')->values()->map(fn ($group): array => [
                    ...$group->only(['id', 'type', 'role', 'visibility', 'sort_order']),
                    'master_block' => $group->masterBlock?->only(['id', 'version_number', 'title', 'content', 'payload', 'status', 'published_at']),
                ])->all(),
            ] : null,
        ];
    }

    private function metadataColumns(string $type): array
    {
        return match ($type) {
            'diagram' => ['id', 'scope_id', 'created_by', 'title', 'project_id', 'visibility'],
            'file' => ['id', 'scope_id', 'created_by', 'name', 'visibility'],
            'lore' => ['id', 'scope_id', 'created_by', 'code', 'project_id', 'visibility'],
            'task' => ['id', 'scope_id', 'created_by', 'title', 'task_key', 'project_id', 'department_id', 'assignee_id', 'status'],
            'lightbook' => ['id', 'scope_id', 'created_by', 'title', 'project_id', 'visibility', 'kind'],
        };
    }
}
