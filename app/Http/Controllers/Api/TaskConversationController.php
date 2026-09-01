<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreTaskCommentRequest;
use App\Http\Resources\ActivityLogResource;
use App\Http\Resources\CommentResource;
use App\Models\ActivityLog;
use App\Models\Scope;
use App\Models\Task;
use App\Services\ContractorContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TaskConversationController extends Controller
{
    public function __construct(private readonly ContractorContext $context) {}

    public function comments(Scope $scope, Task $task): AnonymousResourceCollection
    {
        $this->assertTask($scope, $task);

        return CommentResource::collection($task->comments()->with('creator:id,name')->oldest()->get());
    }

    public function storeComment(StoreTaskCommentRequest $request, Scope $scope, Task $task): CommentResource
    {
        $this->assertTask($scope, $task);
        $data = $request->validated();
        if (! empty($data['parent_id'])) {
            abort_unless($task->comments()->whereKey($data['parent_id'])->exists(), 422, 'The parent comment must belong to this task.');
        }
        $actor = $this->context->actor($request);
        $comment = $task->comments()->create([...$data, 'scope_id' => $scope->id, 'created_by' => $actor->id]);
        ActivityLog::query()->create([
            'scope_id' => $scope->id, 'actor_id' => $actor->id, 'subject_type' => 'task', 'subject_id' => $task->id,
            'action' => 'task.comment_created', 'after' => ['comment_id' => $comment->id], 'context' => ['comment_id' => $comment->id, ...$this->context->auditMetadata($request)],
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return new CommentResource($comment->load('creator:id,name'));
    }

    public function activity(Scope $scope, Task $task): AnonymousResourceCollection
    {
        $this->assertTask($scope, $task);
        $logs = ActivityLog::query()->with('actor:id,name')->where('scope_id', $scope->id)
            ->where(function ($query) use ($task): void {
                $query->where(fn ($direct) => $direct->where('subject_type', 'task')->where('subject_id', $task->id))
                    ->orWhere('context->task_id', $task->id);
            })->latest('created_at')->limit(200)->get();

        return ActivityLogResource::collection($logs);
    }

    private function assertTask(Scope $scope, Task $task): void
    {
        abort_unless($task->scope_id === $scope->id, 404);
    }
}
