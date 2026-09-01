<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookBlockGroup;
use App\Models\BookPage;
use App\Models\Scope;
use App\Services\ContractorAccessService;
use App\Services\ContractorContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    private const TYPES = ['task', 'project', 'book', 'book_page', 'book_block'];

    public function __construct(
        private readonly ContractorAccessService $access,
        private readonly ContractorContext $context,
    ) {}

    public function index(Request $request, Scope $scope): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:200'],
            'types' => ['nullable', 'string', 'max:100'],
            'project_id' => ['nullable', 'string', 'max:26'],
            'user_id' => ['nullable', 'string', 'max:26'],
            'status' => ['nullable', 'string', 'max:40'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $needle = trim($data['q']);
        $pattern = '%'.$needle.'%';
        $types = $this->types($data['types'] ?? null);
        $filters = [
            'project_id' => $data['project_id'] ?? null,
            'user_id' => $data['user_id'] ?? null,
            'status' => $data['status'] ?? null,
            'date_from' => $data['date_from'] ?? null,
            'date_to' => $data['date_to'] ?? null,
        ];
        $actor = $this->context->actor($request);
        $results = collect();

        if ($this->wants($types, 'task') && $this->access->allows($actor, $scope, 'task.view')) {
            $query = $this->access->constrainTasks($scope->tasks()->getQuery(), $actor, $scope)
                ->with(['project:id,title,key,color', 'assignee:id,name']);
            $this->text($query, ['task_key', 'title', 'description', 'result'], $pattern);
            $this->dates($query, $filters);
            $query->when($filters['project_id'], fn (Builder $items, string $id): Builder => $items->where('project_id', $id));
            $query->when($filters['status'], fn (Builder $items, string $status): Builder => $items->where('status', $status));
            $query->when($filters['user_id'], fn (Builder $items, string $id): Builder => $items->where(fn (Builder $users): Builder => $users
                ->where('created_by', $id)->orWhere('assignee_id', $id)->orWhere('customer_id', $id)->orWhere('delegated_agent_id', $id)));
            $results = $results->concat($query->latest('updated_at')->limit(50)->get()->map(fn ($task): array => $this->result(
                'task',
                $task->id,
                $task->title,
                $task->task_key.($task->project ? ' · '.$task->project->title : ''),
                $this->excerpt($task->description ?: $task->result, $needle),
                "/tasks/{$task->id}/edit",
                $task->updated_at,
                $needle,
                $task->task_key,
                ['status' => $task->status, 'project' => $task->project, 'user' => $task->assignee],
            )));
        }

        if ($this->wants($types, 'project') && $this->access->allows($actor, $scope, 'task.view')) {
            $query = $this->access->constrainProjects($scope->projects()->getQuery(), $actor, $scope);
            $this->text($query, ['key', 'title', 'description', 'result'], $pattern);
            $this->dates($query, $filters);
            $query->when($filters['project_id'], fn (Builder $items, string $id): Builder => $items->whereKey($id));
            $query->when($filters['status'], fn (Builder $items, string $status): Builder => $items->where('status', $status));
            $query->when($filters['user_id'], fn (Builder $items, string $id): Builder => $items->where(fn (Builder $users): Builder => $users
                ->where('created_by', $id)
                ->orWhereHas('members', fn (Builder $members): Builder => $members->where('user_id', $id)->where('is_active', true))));
            $results = $results->concat($query->latest('updated_at')->limit(50)->get()->map(fn ($project): array => $this->result(
                'project',
                $project->id,
                $project->title,
                $project->key,
                $this->excerpt($project->description ?: $project->result, $needle),
                "/projects?project={$project->id}",
                $project->updated_at,
                $needle,
                $project->key,
                ['status' => $project->status, 'color' => $project->color],
            )));
        }

        if (array_intersect($types, ['book', 'book_page', 'book_block'])
            && ! $filters['status']
            && $this->access->allows($actor, $scope, 'book.view')) {
            $bookQuery = $this->access->constrainBooks($scope->books()->getQuery(), $actor, $scope);
            $bookQuery->when($filters['project_id'], fn (Builder $items, string $id): Builder => $items->where('project_id', $id));
            $bookIds = (clone $bookQuery)->select('books.id');

            if ($this->wants($types, 'book')) {
                $query = clone $bookQuery;
                $query->with('project:id,title,key,color');
                $query->when($filters['user_id'], fn (Builder $items, string $id): Builder => $items->where('created_by', $id));
                $this->text($query, ['title', 'description', 'cover_svg_text'], $pattern);
                $this->dates($query, $filters);
                $results = $results->concat($query->latest('updated_at')->limit(50)->get()->map(fn ($book): array => $this->result(
                    'book',
                    $book->id,
                    $book->title,
                    $book->project ? $book->project->key.' · '.$book->project->title : 'Booker',
                    $this->excerpt($book->description, $needle),
                    "/books/{$book->id}",
                    $book->updated_at,
                    $needle,
                    null,
                    ['project' => $book->project],
                )));
            }

            if ($this->wants($types, 'book_page')) {
                $query = BookPage::query()->whereIn('book_id', $bookIds)->with('book:id,title');
                $this->text($query, ['title'], $pattern);
                $this->dates($query, $filters);
                $query->when($filters['user_id'], fn (Builder $items, string $id): Builder => $items->where('created_by', $id));
                $results = $results->concat($query->latest('updated_at')->limit(50)->get()->map(fn ($page): array => $this->result(
                    'book_page',
                    $page->id,
                    $page->title,
                    $page->book->title,
                    null,
                    "/books/{$page->book_id}/pages/{$page->id}",
                    $page->updated_at,
                    $needle,
                    null,
                    ['book_id' => $page->book_id],
                )));
            }

            if ($this->wants($types, 'book_block')) {
                $query = BookBlockGroup::query()
                    ->whereHas('page', fn (Builder $pages): Builder => $pages->whereIn('book_id', $bookIds))
                    ->whereHas('masterBlock', function (Builder $blocks) use ($pattern): void {
                        $this->text($blocks, ['title', 'content', 'search_text'], $pattern);
                    })
                    ->with(['page.book:id,title', 'masterBlock:id,group_id,title,content,search_text,updated_at']);
                $this->dates($query, $filters);
                $query->when($filters['user_id'], fn (Builder $items, string $id): Builder => $items->where('created_by', $id));
                $results = $results->concat($query->latest('updated_at')->limit(50)->get()->map(function ($group) use ($needle): array {
                    $block = $group->masterBlock;
                    $title = $block?->title ?: 'Блок '.($group->type ?? 'Booker');

                    return $this->result(
                        'book_block',
                        $group->id,
                        $title,
                        $group->page->book->title.' · '.$group->page->title,
                        $this->excerpt($block?->content ?: $block?->search_text, $needle),
                        "/books/{$group->page->book_id}/pages/{$group->page_id}/blocks/{$group->id}",
                        $block?->updated_at ?: $group->updated_at,
                        $needle,
                        null,
                        ['book_id' => $group->page->book_id, 'page_id' => $group->page_id, 'block_type' => $group->type],
                    );
                }));
            }
        }

        $sorted = $results->sort(function (array $left, array $right): int {
            return ($right['_score'] <=> $left['_score'])
                ?: strcmp((string) $right['updated_at'], (string) $left['updated_at']);
        })->values();
        $facets = $sorted->countBy('type')->map(fn (int $count, string $type): array => ['type' => $type, 'count' => $count])->values();
        $limit = (int) ($data['limit'] ?? 100);

        return response()->json(['data' => [
            'query' => $needle,
            'total' => $sorted->count(),
            'facets' => $facets,
            'results' => $sorted->take($limit)->map(fn (array $item): array => collect($item)->except('_score')->all())->values(),
        ]]);
    }

    /** @return array<int, string> */
    private function types(?string $types): array
    {
        return $types
            ? array_values(array_intersect(self::TYPES, array_filter(explode(',', $types))))
            : self::TYPES;
    }

    /** @param array<int, string> $types */
    private function wants(array $types, string $type): bool
    {
        return in_array($type, $types, true);
    }

    /** @param array<int, string> $columns */
    private function text(Builder $query, array $columns, string $pattern): void
    {
        $patterns = array_values(array_unique([
            $pattern,
            mb_convert_case($pattern, MB_CASE_TITLE, 'UTF-8'),
            mb_strtoupper($pattern, 'UTF-8'),
        ]));
        $query->where(function (Builder $match) use ($columns, $patterns): void {
            foreach ($columns as $column) {
                foreach ($patterns as $variant) {
                    $match->orWhere($column, 'like', $variant);
                }
            }
        });
    }

    /** @param array<string, mixed> $filters */
    private function dates(Builder $query, array $filters): void
    {
        $query->when($filters['date_from'], fn (Builder $items, string $date): Builder => $items->whereDate('created_at', '>=', $date));
        $query->when($filters['date_to'], fn (Builder $items, string $date): Builder => $items->whereDate('created_at', '<=', $date));
    }

    /** @param array<string, mixed> $meta */
    private function result(string $type, string $id, string $title, ?string $subtitle, ?string $snippet, string $href, $updatedAt, string $needle, ?string $key = null, array $meta = []): array
    {
        $search = mb_strtolower($needle);
        $name = mb_strtolower($title);
        $literal = mb_strtolower((string) $key);
        $score = $literal === $search ? 100
            : ($name === $search ? 90
                : (str_starts_with($literal, $search) ? 80
                    : (str_starts_with($name, $search) ? 70
                        : (str_contains($name, $search) ? 50 : 20))));

        return [
            'id' => $id,
            'type' => $type,
            'title' => $title,
            'subtitle' => $subtitle,
            'snippet' => $snippet,
            'href' => $href,
            'updated_at' => $updatedAt?->toISOString(),
            'meta' => $meta,
            '_score' => $score,
        ];
    }

    private function excerpt(?string $value, string $needle): ?string
    {
        $plain = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $value)));
        if ($plain === '') {
            return null;
        }
        $position = mb_stripos($plain, $needle);
        $start = $position === false ? 0 : max(0, $position - 70);
        $excerpt = mb_substr($plain, $start, 220);

        return ($start > 0 ? '…' : '').$excerpt.(mb_strlen($plain) > $start + 220 ? '…' : '');
    }
}
