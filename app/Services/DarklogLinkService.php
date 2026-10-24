<?php

namespace App\Services;

use App\Models\Book;
use App\Models\BookPage;
use App\Models\Contact;
use App\Models\Darklog;
use App\Models\DarklogLink;
use App\Models\Event;
use App\Models\Fact;
use App\Models\FilerFile;
use App\Models\LoreEntry;
use App\Models\PlanItem;
use App\Models\Project;
use App\Models\Scope;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DarklogLinkService
{
    public const TARGETS = [
        'task' => Task::class, 'event' => Event::class, 'project' => Project::class,
        'book' => Book::class, 'book_page' => BookPage::class, 'fact' => Fact::class, 'contact' => Contact::class,
        'lore' => LoreEntry::class, 'plan_item' => PlanItem::class, 'darklog' => Darklog::class,
        'file' => FilerFile::class,
    ];

    public function __construct(private readonly ContractorAccessService $access, private readonly FilerAccessService $files, private readonly PrivateRecordAccessService $privateRecords) {}

    public function projectAllowed(User $actor, Scope $scope, ?string $projectId, string $ability = 'task.view'): bool
    {
        if (! $this->access->allows($actor, $scope, $ability)) {
            return false;
        }
        if ($projectId === null) {
            return $this->access->canAccessUnprojected($actor, $scope);
        }
        $project = Project::withTrashed()->find($projectId);

        return $project !== null && $this->access->canAccessProject($actor, $scope, $project, $ability);
    }

    public function visibleDarklogs(User $actor, Scope $scope): Builder
    {
        $projects = $this->access->constrainProjects(Project::withTrashed()->where('scope_id', $scope->id), $actor, $scope)->select('id');

        return Darklog::query()->where('scope_id', $scope->id)->where(function (Builder $query) use ($projects, $actor, $scope): void {
            $query->whereIn('project_id', $projects);
            if ($this->access->canAccessUnprojected($actor, $scope)) {
                $query->orWhereNull('project_id');
            }
        });
    }

    public function canView(User $actor, Scope $scope, Model $target): bool
    {
        if (! ScopeModuleService::entityEnabled($scope, $target) || $target->scope_id !== $scope->id) {
            return false;
        }
        if ($target instanceof Task || $target instanceof Project || $target instanceof Event || $target instanceof Book) {
            if ($target instanceof Event && ! $this->projectAllowed($actor, $scope, $target->project_id)) {
                return false;
            }

            return $this->files->subject($actor, $scope, $target);
        }
        if ($target instanceof FilerFile) {
            return $this->files->file($actor, $scope, $target);
        }
        if ($target instanceof BookPage) {
            $target->loadMissing('book.project');

            return $target->book !== null && $this->access->canAccessBook($actor, $scope, $target->book);
        }
        if ($target instanceof Fact || $target instanceof Contact) {
            return $this->privateRecords->read($actor, $scope, $target);
        }
        if ($target instanceof LoreEntry) {
            return ($target->visibility !== 'private' || $target->created_by === $actor->id)
                && $this->projectAllowed($actor, $scope, $target->project_id);
        }

        return $this->projectAllowed($actor, $scope, $target->project_id);
    }

    public function resolve(User $actor, Scope $scope, string $type, string $id): ?Model
    {
        $class = self::TARGETS[$type] ?? null;
        if ($class === null) {
            return null;
        }
        $target = $class::query()->where('scope_id', $scope->id)->find($id);

        return $target !== null && $this->canView($actor, $scope, $target) ? $target : null;
    }

    public function describe(string $type, Model $target): array
    {
        $title = $target->title ?? $target->label ?? $target->code ?? $target->name ?? 'Запись';
        $at = $target->created_at;
        $href = match ($type) {
            'task' => '/tasks/'.$target->id.'/edit',
            'event' => '/events?event='.$target->id,
            'project' => '/projects?project='.$target->id,
            'book' => '/books/'.$target->id,
            'book_page' => '/books/'.$target->book_id.'/pages/'.$target->id,
            'lore' => '/lore/'.$target->scope_id.'/'.$target->id,
            'darklog' => '/darklog/'.$target->scope_id.'/'.$target->id,
            'plan_item' => '/plans?plan='.$target->id,
            'file' => '/files?file='.$target->id,
            'contact' => '/contacts/'.$target->scope_id.'/'.$target->id,
            'fact' => '/factor/'.$target->scope_id.'/'.$target->id,
            default => '/factor?fact='.$target->id,
        };
        if ($target instanceof Task) {
            $at = $target->completed_at ?? $target->created_at;
        } elseif ($target instanceof Event) {
            $at = $target->starts_at ?? $target->occurred_at ?? $target->created_at;
        } elseif ($target instanceof LoreEntry) {
            $revision = $target->revisions()->where('effective_from', '<=', now())->latest('effective_from')->latest('version')->first();
            $title = $revision?->title ?? $target->code;
            $at = $revision?->effective_from ?? $target->created_at;
        }

        return ['id' => $target->id, 'type' => $type, 'title' => $title, 'code' => $target instanceof Darklog ? 'DRK-'.$target->number : ($target->task_key ?? $target->code ?? $target->key),
            'href' => $href, 'at' => $at, 'status' => $target->status];
    }

    public function present(User $actor, Scope $scope, DarklogLink $link): ?array
    {
        $target = $this->resolve($actor, $scope, $link->target_type, $link->target_id);

        return $target ? ['id' => $link->id, 'relation' => $link->relation, 'note' => $link->note, 'linked_at' => $link->created_at, 'target' => $this->describe($link->target_type, $target)] : null;
    }

    public function candidates(User $actor, Scope $scope, string $type, string $term): array
    {
        $class = self::TARGETS[$type];
        $query = $class::query()->where('scope_id', $scope->id);
        if (in_array($type, ['fact', 'contact'], true)) {
            $query = $this->privateRecords->query($class, $actor, $scope);
        }
        if ($type === 'task') {
            $query = $this->access->constrainTasks($query, $actor, $scope);
        }
        if ($type === 'darklog') {
            $query = $this->visibleDarklogs($actor, $scope);
        }
        if ($term !== '') {
            $field = match ($type) {
                'fact' => 'label', 'contact' => 'name', 'lore' => 'code', 'file' => 'name', default => 'title'
            };
            $query->where(function (Builder $q) use ($field, $type, $term): void {
                $q->where($field, 'like', '%'.$term.'%');
                if ($type === 'task') {
                    $q->orWhere('task_key', 'like', '%'.$term.'%');
                }
                if ($type === 'darklog' && preg_match('/^(?:DRK-)?(\d+)$/i', $term, $matches)) {
                    $q->orWhere('number', (int) $matches[1]);
                }
            });
        }

        return $query->latest()->limit(100)->get()->filter(fn (Model $target): bool => $this->canView($actor, $scope, $target))
            ->take(30)->map(fn (Model $target): array => $this->describe($type, $target))->values()->all();
    }
}
