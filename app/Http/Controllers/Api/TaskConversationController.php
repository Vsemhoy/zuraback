<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreTaskCommentRequest;
use App\Http\Requests\Api\UpdateTaskCommentRequest;
use App\Http\Resources\ActivityLogResource;
use App\Http\Resources\CommentResource;
use App\Models\ActivityLog;
use App\Models\Comment;
use App\Models\Scope;
use App\Models\Task;
use App\Services\ContractorAccessService;
use App\Services\ContractorContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskConversationController extends Controller
{
    public function __construct(private readonly ContractorContext $context, private readonly ContractorAccessService $access) {}

    public function comments(Request $request, Scope $scope, Task $task): AnonymousResourceCollection
    {
        $this->assertTask($request, $scope, $task);

        return CommentResource::collection($task->comments()->with('creator:id,name')->oldest()->get());
    }

    public function storeComment(StoreTaskCommentRequest $request, Scope $scope, Task $task): CommentResource
    {
        $this->assertTask($request, $scope, $task, 'task.update');
        abort_if($task->status === 'cancelled', 422, 'Сначала восстановите удалённую задачу.');
        $data = $request->validated();
        $actor = $this->context->actor($request);
        $comment = DB::transaction(function () use ($request, $scope, $task, $data, $actor): Comment {
            $kind = $data['kind'] ?? 'comment';
            $parent = ! empty($data['parent_id']) ? $task->comments()->whereKey($data['parent_id'])->lockForUpdate()->first() : null;
            if (! empty($data['parent_id']) && ! $parent) {
                throw ValidationException::withMessages(['parent_id' => 'Родительский комментарий должен принадлежать этой задаче.']);
            }
            if ($parent?->parent_id) {
                throw ValidationException::withMessages(['parent_id' => 'Допустим только один уровень ответов.']);
            }
            if ($kind === 'question' && $parent) {
                throw ValidationException::withMessages(['kind' => 'Вопрос создаётся отдельным комментарием.']);
            }
            if ($kind === 'answer' && $parent?->kind !== 'question') {
                throw ValidationException::withMessages(['kind' => 'Ответ должен быть привязан к вопросу.']);
            }
            $comment = $task->comments()->create([...$data, 'kind' => $kind, 'scope_id' => $scope->id, 'created_by' => $actor->id]);
            if ($kind === 'answer') {
                $this->setAnswered($request, $scope, $task, $parent, true);
            }
            ActivityLog::query()->create([
                'scope_id' => $scope->id, 'actor_id' => $actor->id, 'subject_type' => 'task', 'subject_id' => $task->id,
                'action' => 'task.comment_created', 'after' => $comment->only(['id', 'kind', 'parent_id', 'content']), 'context' => ['comment_id' => $comment->id, ...$this->context->auditMetadata($request)],
                'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
            ]);

            return $comment;
        });

        return new CommentResource($comment->load('creator:id,name'));
    }

    public function destroyComment(Request $request, Scope $scope, Task $task, Comment $comment): Response
    {
        $this->assertTask($request, $scope, $task, 'task.update');
        abort_if($task->status === 'cancelled', 422, 'Сначала восстановите удалённую задачу.');
        abort_unless($comment->commentable_type === 'task' && $comment->commentable_id === $task->id, 404);
        $actor = $this->context->actor($request);
        abort_unless($comment->created_by === $actor->id || $task->created_by === $actor->id || $scope->owner_id === $actor->id, 403);
        DB::transaction(function () use ($request, $scope, $task, $comment): void {
            $parent = $comment->parent_id ? $task->comments()->whereKey($comment->parent_id)->lockForUpdate()->first() : null;
            $comment->delete();
            $task->comments()->where('parent_id', $comment->id)->delete();
            if ($comment->kind === 'answer' && $parent?->kind === 'question'
                && ! $task->comments()->where('parent_id', $parent->id)->where('kind', 'answer')->exists()) {
                $this->setAnswered($request, $scope, $task, $parent, false);
            }
        });
        ActivityLog::query()->create([
            'scope_id' => $scope->id, 'actor_id' => $actor->id, 'subject_type' => 'task', 'subject_id' => $task->id,
            'action' => 'task.comment_deleted', 'before' => ['comment_id' => $comment->id, 'content' => $comment->content],
            'context' => ['comment_id' => $comment->id, ...$this->context->auditMetadata($request)],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->noContent();
    }

    public function updateComment(UpdateTaskCommentRequest $request, Scope $scope, Task $task, Comment $comment): CommentResource
    {
        $this->assertTask($request, $scope, $task, 'task.update');
        abort_if($task->status === 'cancelled', 422, 'Сначала восстановите удалённую задачу.');
        abort_unless($comment->commentable_type === 'task' && $comment->commentable_id === $task->id, 404);
        abort_unless($comment->kind === 'question', 422, 'Флаг «Отвечен» доступен только вопросу.');
        $actor = $this->context->actor($request);
        abort_unless($comment->created_by === $actor->id || $task->created_by === $actor->id || $scope->owner_id === $actor->id, 403);
        DB::transaction(function () use ($request, $scope, $task, $comment): void {
            $locked = $task->comments()->whereKey($comment->id)->lockForUpdate()->firstOrFail();
            $this->setAnswered($request, $scope, $task, $locked, $request->boolean('is_answered'));
        });

        return new CommentResource($comment->fresh()->load('creator:id,name'));
    }

    public function activity(Request $request, Scope $scope, Task $task): AnonymousResourceCollection
    {
        $this->assertTask($request, $scope, $task);
        $logs = ActivityLog::query()->with('actor:id,name')->where('scope_id', $scope->id)
            ->where(function ($query) use ($task): void {
                $query->where(fn ($direct) => $direct->where('subject_type', 'task')->where('subject_id', $task->id))
                    ->orWhere('context->task_id', $task->id);
            })->latest('created_at')->limit(200)->get();

        return ActivityLogResource::collection($logs);
    }

    private function assertTask(Request $request, Scope $scope, Task $task, string $ability = 'task.view'): void
    {
        abort_unless($this->access->canAccessTask($this->context->actor($request), $scope, $task, $ability), 404);
    }

    private function setAnswered(Request $request, Scope $scope, Task $task, Comment $question, bool $answered): void
    {
        if ($question->is_answered === $answered) {
            return;
        }
        $before = $question->is_answered;
        $question->update(['is_answered' => $answered]);
        ActivityLog::query()->create([
            'scope_id' => $scope->id, 'actor_id' => $this->context->actor($request)->id, 'subject_type' => 'task', 'subject_id' => $task->id,
            'action' => $answered ? 'task.question_answered' : 'task.question_reopened',
            'before' => ['is_answered' => $before], 'after' => ['is_answered' => $answered],
            'context' => ['comment_id' => $question->id, ...$this->context->auditMetadata($request)],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);
    }
}
