<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Book;
use App\Models\ContentCategory;
use App\Models\Event;
use App\Models\EventSection;
use App\Models\Scope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContentStructureService
{
    public function __construct(private readonly ContractorAccessService $access, private readonly ContractorContext $context) {}

    public function authorize(Request $request, Scope $scope, string $module, string $operation = 'view'): User
    {
        abort_unless(in_array($module, ['eventor', 'lightbook'], true), 404);
        abort_unless($scope->moduleEnabled($module === 'eventor' ? 'eventor' : 'content'), 403, 'Модуль отключён владельцем скоупа.');
        $actor = $this->context->actor($request);
        abort_unless($this->access->allows($actor, $scope, ($module === 'eventor' ? 'task.' : 'book.').$operation), 403, 'Нет права управлять структурой этого модуля.');
        if ($module === 'lightbook' && $scope->owner_id !== $actor->id) {
            abort_if($this->access->membership($actor, $scope)?->book_access_mode === 'none', 403);
        }

        return $actor;
    }

    /** Navigation metadata never grants access to the records classified by it. */
    public function catalog(Request $request, Scope $scope, string $module): array
    {
        $actor = $this->authorize($request, $scope, $module);
        $prefix = $module === 'eventor' ? 'task.' : 'book.';
        $canWrite = $this->access->allows($actor, $scope, $prefix.'update');
        $present = fn (Model $record): array => [...$record->toArray(), 'can_manage' => $canWrite && $this->canManage($actor, $scope, $record)];

        return ['sections' => $this->sections($scope, $actor)->map($present)->values(),
            'categories' => ContentCategory::where('scope_id', $scope->id)->orderBy('sort_order')->orderBy('name')->orderBy('id')->get()->map($present),
            'can_create' => $this->access->allows($actor, $scope, $prefix.'create')];
    }

    public function sections(Scope $scope, User $actor): Collection
    {
        $rows = EventSection::where('scope_id', $scope->id)->orderBy('sort_order')->orderBy('name')->orderBy('id')->get()->keyBy('id');

        return $rows->filter(function (EventSection $section) use ($rows, $scope, $actor): bool {
            $seen = [];
            $node = $section;
            while ($node) {
                if (isset($seen[$node->id]) || ($node->visibility === 'private' && $node->created_by !== $actor->id && $scope->owner_id !== $actor->id)) {
                    return false;
                }
                $seen[$node->id] = true;
                $node = $node->parent_id ? $rows->get($node->parent_id) : null;
            }

            return true;
        })->values();
    }

    public function save(Request $request, Scope $scope, string $module, string $kind, array $data, ?string $id = null): Model
    {
        $actor = $this->authorize($request, $scope, $module, $id ? 'update' : 'create');

        return DB::transaction(function () use ($request, $scope, $kind, $data, $id, $actor): Model {
            Scope::whereKey($scope->id)->lockForUpdate()->firstOrFail();
            $class = $this->model($kind);
            $record = $id ? $class::where('scope_id', $scope->id)->lockForUpdate()->findOrFail($id) : new $class(['scope_id' => $scope->id, 'created_by' => $actor->id]);
            if ($id) {
                abort_unless($this->canManage($actor, $scope, $record), 403);
                abort_unless($record->revision === ($data['revision'] ?? null), 409, 'Структура изменилась. Обновите список и повторите правку.');
            }
            $before = $id ? $record->attributesToArray() : null;
            unset($data['revision']);
            $record->fill($data);
            if ($record instanceof EventSection) {
                $record->visibility = $data['visibility'] ?? ($id ? $record->visibility : 'scope');
                if (! $id || $record->isDirty(['parent_id', 'visibility'])) {
                    $this->validateParent($scope, $actor, $record);
                }
            }
            $duplicate = $class::where('scope_id', $scope->id);
            if ($id) {
                $duplicate->whereKeyNot($id);
            }
            if ($duplicate->pluck('name')->contains(fn (string $name): bool => mb_strtolower(trim($name)) === mb_strtolower(trim($record->name)))) {
                throw ValidationException::withMessages(['name' => 'Такое название уже есть в этом скоупе.']);
            }
            $record->name = trim($record->name);
            if ($id) {
                $record->revision++;
            }
            $record->save();
            $this->log($request, $scope, $record, $kind, $id ? 'updated' : 'created', $before);

            return $record;
        });
    }

    public function delete(Request $request, Scope $scope, string $module, string $kind, string $id, int $revision): void
    {
        $actor = $this->authorize($request, $scope, $module, 'update');
        DB::transaction(function () use ($request, $scope, $kind, $id, $revision, $actor): void {
            Scope::whereKey($scope->id)->lockForUpdate()->firstOrFail();
            $class = $this->model($kind);
            $record = $class::where('scope_id', $scope->id)->lockForUpdate()->findOrFail($id);
            abort_unless($this->canManage($actor, $scope, $record), 403);
            abort_unless($record->revision === $revision, 409, 'Структура изменилась. Обновите список.');
            $field = $kind === 'sections' ? 'section_id' : 'category_id';
            if (Event::withTrashed()->where($field, $id)->exists() || Book::withTrashed()->where($field, $id)->exists()
                || ($kind === 'sections' && EventSection::where('parent_id', $id)->exists())) {
                throw ValidationException::withMessages(['structure' => 'Сначала перенесите записи и дочерние секции. Вместо удаления можно отправить в архив.']);
            }
            $this->log($request, $scope, $record, $kind, 'deleted', $record->attributesToArray());
            $record->delete();
        });
    }

    public function assertReferences(Scope $scope, User $actor, array $data, ?Model $current = null): void
    {
        foreach (['section_id', 'category_id'] as $field) {
            if (empty($data[$field]) || ($current && $data[$field] === $current->$field)) {
                continue;
            }
            if ($field === 'section_id') {
                $rows = $this->sections($scope, $actor)->keyBy('id');
                $node = $rows->get($data[$field]);
                $valid = $node !== null;
                while ($node) {
                    $valid = $valid && ! $node->is_archived;
                    $node = $node->parent_id ? $rows->get($node->parent_id) : null;
                }
            } else {
                $valid = ContentCategory::where('scope_id', $scope->id)->whereKey($data[$field])->where('is_archived', false)->exists();
            }
            if (! $valid) {
                throw ValidationException::withMessages([$field => 'Выберите доступную неархивную запись из этого скоупа.']);
            }
        }
    }

    public function filter(Builder $query, Request $request, Scope $scope): Builder
    {
        foreach (['project_id', 'section_id', 'category_id'] as $field) {
            $value = $request->query($field);
            if (! $value) {
                continue;
            }
            if ($value === 'none') {
                $query->whereNull($field);
            } elseif ($field === 'section_id') {
                $rows = $this->sections($scope, $this->context->actor($request));
                abort_unless($rows->contains('id', $value), 404);
                $ids = [$value];
                do {
                    $before = count($ids);
                    $ids = array_values(array_unique([...$ids, ...$rows->whereIn('parent_id', $ids)->pluck('id')->all()]));
                } while (count($ids) !== $before);
                $query->whereIn('section_id', $ids);
            } else {
                $query->where($field, $value);
            }
        }

        return $query;
    }

    private function validateParent(Scope $scope, User $actor, EventSection $section): void
    {
        if (! $section->parent_id) {
            return;
        }
        $rows = $this->sections($scope, $actor)->keyBy('id');
        $node = $rows->get($section->parent_id);
        if (! $node) {
            throw ValidationException::withMessages(['parent_id' => 'Родительская секция недоступна в этом скоупе.']);
        }
        $seen = [$section->id => true];
        while ($node) {
            if (isset($seen[$node->id]) || $node->is_archived || ($node->visibility === 'private' && $section->visibility !== 'private')) {
                throw ValidationException::withMessages(['parent_id' => 'Нельзя создать цикл, вложиться в архив или открыть дочернюю секцию приватной папки.']);
            }
            $seen[$node->id] = true;
            $node = $node->parent_id ? $rows->get($node->parent_id) : null;
        }
    }

    private function canManage(User $actor, Scope $scope, Model $record): bool
    {
        return $record->created_by === $actor->id || $scope->owner_id === $actor->id;
    }

    private function model(string $kind): string
    {
        return match ($kind) {
            'sections' => EventSection::class, 'categories' => ContentCategory::class,
            default => abort(404),
        };
    }

    private function log(Request $request, Scope $scope, Model $record, string $kind, string $action, ?array $before): void
    {
        ActivityLog::create(['scope_id' => $scope->id, 'actor_id' => $this->context->actor($request)->id,
            'subject_type' => 'scope', 'subject_id' => $scope->id, 'action' => 'content.'.$kind.'.'.$action,
            'before' => $before, 'after' => $action === 'deleted' ? null : $record->attributesToArray(),
            'context' => ['record_id' => $record->id, ...$this->context->auditMetadata($request)]]);
    }
}
