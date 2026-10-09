<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Book;
use App\Models\BookBlockGroup;
use App\Models\BookPage;
use App\Models\Comment;
use App\Models\Scope;
use App\Models\Task;
use App\Models\User;
use App\Services\ContractorAccessService;
use App\Services\ContractorContext;
use App\Services\KpiProfileService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class DashboardController extends Controller
{
    public function __construct(
        private readonly ContractorAccessService $access,
        private readonly ContractorContext $context,
        private readonly KpiProfileService $profiles,
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
            ->with(['project:id,title,key,color', 'assignee:id,name,type', 'creator:id,name', 'department:id,name'])
            ->orderByRaw('due_at IS NULL')
            ->orderBy('due_at')
            ->orderByDesc('priority')
            ->limit(8)
            ->get();

        $recentTasks = (clone $tasks)
            ->with(['project:id,title,key,color', 'assignee:id,name,type', 'creator:id,name', 'department:id,name'])
            ->latest()
            ->limit(7)
            ->get();
        $recentProjects = (clone $projects)->withCount(['tasks', 'books'])->latest()->limit(5)->get();
        $recentBooks = (clone $books)->with(['project:id,title,key,color', 'creator:id,name,type'])->withCount('pages')->latest()->limit(5)->get();

        $personal = (clone $tasks)->where(fn ($q) => $q->where('assignee_id', $actor->id)->orWhere('created_by', $actor->id));
        $relations = ['project:id,title,key,color', 'assignee:id,name,type', 'creator:id,name', 'department:id,name'];
        $assigned = (clone $tasks)->where('assignee_id', $actor->id)->where('status', '!=', 'cancelled')->with($relations)->latest()->limit(8)->get();
        $outgoing = (clone $tasks)->where('created_by', $actor->id)->where('status', '!=', 'cancelled')->with($relations)->latest()->limit(8)->get();
        $done = (clone $personal)->where('status', 'done')->with($relations)->orderByDesc('completed_at')->limit(8)->get();
        $departmentId = $this->access->membership($actor, $scope)?->department_id;
        $queue = $departmentId ? (clone $tasks)->where('department_id', $departmentId)->whereNull('assignee_id')->whereNotIn('status', ['done', 'cancelled'])->with($relations)->oldest()->limit(15)->get() : collect();
        $updates = ActivityLog::where('scope_id', $scope->id)->where('subject_type', 'task')
            ->whereIn('subject_id', (clone $personal)->select('tasks.id'))->where('actor_id', '!=', $actor->id)
            ->with(['actor:id,name', 'subject'])->latest()->limit(20)->get()->map(function ($log): array {
                $before = $log->before ?? [];
                $after = $log->after ?? [];
                $fields = collect(['status' => 'изменил статус', 'assignee_id' => 'сменил исполнителя', 'due_at' => 'изменил срок', 'result' => 'обновил результат', 'title' => 'изменил название', 'description' => 'обновил описание', 'department_id' => 'сменил отдел'])
                    ->filter(fn ($label, $key) => array_key_exists($key, $after) && ($before[$key] ?? null) != $after[$key])->values()->all();
                $message = str_contains($log->action, 'comment') ? 'обновил комментарии' : ($log->action === 'task.created' ? 'поставил задачу' : (implode(', ', $fields) ?: 'обновил задачу'));

                return ['id' => $log->id, 'actor' => $log->actor, 'task' => $log->subject?->only(['id', 'task_key', 'title']), 'message' => $message, 'status' => $after['status'] ?? null, 'created_at' => $log->created_at];
            });

        return new JsonResource([
            'generated_at' => now(),
            'summary' => [
                'my_open_tasks' => (clone $tasks)->where('assignee_id', $actor->id)->whereNotIn('status', ['done', 'cancelled'])->count(),
                'my_overdue_tasks' => (clone $tasks)->where('assignee_id', $actor->id)->whereNotIn('status', ['done', 'cancelled'])->where('due_at', '<', now())->count(),
                'projects' => (clone $projects)->count(),
                'books' => (clone $books)->count(),
            ],
            'assigned_to_me' => $assigned->map(fn ($task) => $this->taskRow($task)),
            'created_by_me' => $outgoing->map(fn ($task) => $this->taskRow($task)),
            'recently_completed' => $done->map(fn ($task) => $this->taskRow($task)),
            'department_queue' => $queue->map(fn ($task) => $this->taskRow($task)),
            'task_updates' => $updates,
            'can_claim' => (bool) $actor->is_executor && $this->access->allows($actor, $scope, 'task.update'),
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
            'creator' => $task->creator,
            'department' => $task->department,
            'completed_at' => $task->completed_at,
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function bookComments(Scope $scope, User $actor): Collection
    {
        return Comment::query()
            ->where('scope_id', $scope->id)
            ->whereIn('commentable_type', ['book', 'book_page', 'book_block_group'])
            ->with(['creator:id,name,type,profile', 'commentable'])
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
                    'creator' => $comment->creator ? [
                        'id' => $comment->creator->id,
                        'name' => $comment->creator->name,
                        'type' => $comment->creator->type,
                        'avatar' => $comment->creator->profile['avatar'] ?? null,
                    ] : null,
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
        $completed = $scope->tasks()->includedInReports()
            ->where('status', 'done')
            ->whereNotNull('assignee_id')
            ->whereNotNull('kpi_id')
            ->whereBetween('due_at', [$month, $month->endOfMonth()])
            ->get(['assignee_id', 'kpi_id'])
            ->groupBy(fn (Task $task): string => $task->assignee_id.'|'.$task->kpi_id)
            ->map->count();
        $targets = [
            'salary' => (int) data_get($scope->settings, 'kpi.salary_target_points', 100),
            'bonus' => (int) data_get($scope->settings, 'kpi.bonus_target_points', 75),
            'bonus_cap' => (int) data_get($scope->settings, 'kpi.bonus_cap_percent', 75),
        ];
        $versions = $this->profiles->versions($scope);
        $people = User::query()
            ->whereIn('type', ['real', 'virtual'])
            ->where('is_executor', true)
            ->where(fn ($query) => $query->whereKey($scope->owner_id)->orWhereHas('scopeMemberships', fn ($members) => $members->where('scope_id', $scope->id)->where('is_active', true)))
            ->orderBy('name')
            ->get(['id', 'name', 'position', 'type'])
            ->map(function (User $person) use ($scope, $month, $completed, $versions): array {
                $profile = $this->profiles->resolve($scope, $person->id, $month->format('Y-m'), $versions);
                $areas = collect($profile['items'])->map(fn ($item) => (object) $item);
                $targets = ['salary' => $profile['targets']['salary_target_points'], 'bonus' => $profile['targets']['bonus_target_points'], 'bonus_cap' => $profile['targets']['bonus_cap_percent']];
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
