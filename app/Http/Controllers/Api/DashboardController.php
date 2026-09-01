<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\BookBlockGroup;
use App\Models\BookPage;
use App\Models\Comment;
use App\Models\Scope;
use App\Models\Task;
use App\Models\User;
use App\Services\ContractorAccessService;
use App\Services\ContractorContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class DashboardController extends Controller
{
    public function __construct(
        private readonly ContractorAccessService $access,
        private readonly ContractorContext $context,
    ) {}

    public function show(Request $request, Scope $scope): JsonResource
    {
        $actor = $this->context->actor($request);
        $tasks = $this->access->constrainTasks($scope->tasks()->getQuery(), $actor, $scope);
        $projects = $this->access->constrainProjects($scope->projects()->getQuery(), $actor, $scope);
        $books = $this->access->constrainBooks($scope->books()->getQuery(), $actor, $scope);

        $myTasks = (clone $tasks)
            ->where('assignee_id', $actor->id)
            ->whereNotIn('status', ['done', 'cancelled'])
            ->with(['project:id,title,key,color', 'assignee:id,name,type'])
            ->orderByRaw('due_at IS NULL')
            ->orderBy('due_at')
            ->orderByDesc('priority')
            ->limit(8)
            ->get();

        $recentTasks = (clone $tasks)
            ->with(['project:id,title,key,color', 'assignee:id,name,type'])
            ->latest()
            ->limit(7)
            ->get();
        $recentProjects = (clone $projects)->withCount(['tasks', 'books'])->latest()->limit(5)->get();
        $recentBooks = (clone $books)->with(['project:id,title,key,color', 'creator:id,name,type'])->withCount('pages')->latest()->limit(5)->get();

        return new JsonResource([
            'generated_at' => now(),
            'summary' => [
                'my_open_tasks' => (clone $tasks)->where('assignee_id', $actor->id)->whereNotIn('status', ['done', 'cancelled'])->count(),
                'my_overdue_tasks' => (clone $tasks)->where('assignee_id', $actor->id)->whereNotIn('status', ['done', 'cancelled'])->where('due_at', '<', now())->count(),
                'projects' => (clone $projects)->count(),
                'books' => (clone $books)->count(),
            ],
            'my_tasks' => $myTasks->map(fn (Task $task): array => $this->taskRow($task))->values(),
            'book_comments' => $this->bookComments($scope, $actor),
            'kpi' => $this->kpiSnapshot($scope, $actor),
            'recent' => [
                'tasks' => $recentTasks->map(fn (Task $task): array => $this->taskRow($task))->values(),
                'projects' => $recentProjects->map(fn ($project): array => [
                    'id' => $project->id,
                    'key' => $project->key,
                    'title' => $project->title,
                    'color' => $project->color,
                    'status' => $project->status,
                    'tasks_count' => $project->tasks_count,
                    'books_count' => $project->books_count,
                    'created_at' => $project->created_at,
                ])->values(),
                'books' => $recentBooks->map(fn (Book $book): array => [
                    'id' => $book->id,
                    'title' => $book->title,
                    'visibility' => $book->visibility,
                    'pages_count' => $book->pages_count,
                    'project' => $book->project,
                    'creator' => $book->creator,
                    'created_at' => $book->created_at,
                ])->values(),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function taskRow(Task $task): array
    {
        return [
            'id' => $task->id,
            'task_key' => $task->task_key,
            'title' => $task->title,
            'status' => $task->status,
            'priority' => $task->priority,
            'due_at' => $task->due_at,
            'created_at' => $task->created_at,
            'project' => $task->project,
            'assignee' => $task->assignee,
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function bookComments(Scope $scope, User $actor): Collection
    {
        return Comment::query()
            ->where('scope_id', $scope->id)
            ->whereIn('commentable_type', ['book', 'book_page', 'book_block_group'])
            ->with(['creator:id,name,type', 'commentable'])
            ->latest()
            ->limit(30)
            ->get()
            ->map(function (Comment $comment) use ($actor, $scope): ?array {
                $target = $comment->commentable;
                if ($target instanceof Book) {
                    $target->loadMissing('project');
                    $book = $target;
                    $page = null;
                    $href = "/books/{$book->id}";
                } elseif ($target instanceof BookPage) {
                    $target->loadMissing('book.project');
                    $book = $target->book;
                    $page = $target;
                    $href = "/books/{$book->id}/pages/{$page->id}";
                } elseif ($target instanceof BookBlockGroup) {
                    $target->loadMissing('page.book.project');
                    $book = $target->page?->book;
                    $page = $target->page;
                    $href = $book && $page ? "/books/{$book->id}/pages/{$page->id}/blocks/{$target->id}" : null;
                } else {
                    return null;
                }

                if (! $book || ! $href || ! $this->access->canAccessBook($actor, $scope, $book)) {
                    return null;
                }

                return [
                    'id' => $comment->id,
                    'content' => $comment->content,
                    'creator' => $comment->creator,
                    'book' => ['id' => $book->id, 'title' => $book->title],
                    'page' => $page ? ['id' => $page->id, 'title' => $page->title] : null,
                    'href' => $href,
                    'created_at' => $comment->created_at,
                ];
            })
            ->filter()
            ->take(6)
            ->values();
    }

    /** @return array<string, mixed> */
    private function kpiSnapshot(Scope $scope, User $actor): array
    {
        $month = CarbonImmutable::now()->startOfMonth();
        $areas = $scope->kpis()->where('is_active', true)->orderBy('sort_order')->get();
        $completed = $scope->tasks()
            ->where('status', 'done')
            ->whereNotNull('assignee_id')
            ->whereNotNull('kpi_id')
            ->whereBetween('completed_at', [$month, $month->endOfMonth()])
            ->get(['assignee_id', 'kpi_id'])
            ->groupBy(fn (Task $task): string => $task->assignee_id.'|'.$task->kpi_id)
            ->map->count();
        $targets = [
            'salary' => (int) data_get($scope->settings, 'kpi.salary_target_points', 100),
            'bonus' => (int) data_get($scope->settings, 'kpi.bonus_target_points', 75),
            'bonus_cap' => (int) data_get($scope->settings, 'kpi.bonus_cap_percent', 75),
        ];
        $people = User::query()
            ->whereIn('type', ['real', 'virtual'])
            ->where('is_executor', true)
            ->where(fn ($query) => $query->whereKey($scope->owner_id)->orWhereHas('scopeMemberships', fn ($members) => $members->where('scope_id', $scope->id)->where('is_active', true)))
            ->orderBy('name')
            ->get(['id', 'name', 'position', 'type'])
            ->map(function (User $person) use ($areas, $completed, $targets): array {
                $rows = $areas->map(function ($area) use ($completed, $person): array {
                    $count = (int) ($completed[$person->id.'|'.$area->id] ?? 0);
                    $qualified = $count >= $area->minimum_completed_tasks;

                    return [
                        'id' => $area->id,
                        'name' => $area->name,
                        'kind' => $area->kind,
                        'points' => $area->points,
                        'minimum_completed_tasks' => $area->minimum_completed_tasks,
                        'completed_tasks' => $count,
                        'qualified' => $qualified,
                        'awarded_points' => $qualified ? $area->points : 0,
                    ];
                });
                $salaryPoints = (int) $rows->where('kind', 'salary')->sum('awarded_points');
                $bonusPoints = (int) $rows->where('kind', 'bonus')->sum('awarded_points');

                return [
                    'user' => $person,
                    'salary_points' => $salaryPoints,
                    'salary_target' => $targets['salary'],
                    'salary_progress' => min(100, (int) round($salaryPoints / max(1, $targets['salary']) * 100)),
                    'bonus_points' => $bonusPoints,
                    'bonus_target' => $targets['bonus'],
                    'payable_bonus_percent' => min($targets['bonus_cap'], $bonusPoints),
                    'completed_tasks' => (int) $rows->sum('completed_tasks'),
                    'areas' => $rows->values(),
                ];
            });

        return [
            'month' => $month->format('Y-m'),
            'me' => $people->firstWhere('user.id', $actor->id),
            'team' => $people->where('user.id', '!=', $actor->id)->sortByDesc('bonus_points')->values(),
        ];
    }
}
