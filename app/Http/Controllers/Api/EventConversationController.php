<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreTaskCommentRequest;
use App\Http\Resources\CommentResource;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Models\Scope;
use App\Services\ContractorContext;
use App\Services\ContractorAccessService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EventConversationController extends Controller
{
    public function __construct(private readonly ContractorContext $context, private readonly ContractorAccessService $access) {}

    public function comments(Request $request, Scope $scope, Event $event): AnonymousResourceCollection
    {
        $this->assertEvent($request, $scope, $event);
        return CommentResource::collection($event->comments()->with('creator:id,name')->oldest()->get());
    }

    public function storeComment(StoreTaskCommentRequest $request, Scope $scope, Event $event): CommentResource
    {
        $this->assertEvent($request, $scope, $event);
        $event->loadMissing('project:id,scope_id,event_comments_enabled');
        abort_unless($event->comments_enabled ?? $event->project?->event_comments_enabled ?? true, 403, 'Comments are disabled for this event.');
        $data = $request->validated();
        if (! empty($data['parent_id'])) abort_unless($event->comments()->whereKey($data['parent_id'])->exists(), 422, 'The parent comment must belong to this event.');
        $comment = $event->comments()->create([...$data, 'scope_id' => $scope->id, 'created_by' => $this->context->actor($request)->id]);
        return new CommentResource($comment->load('creator:id,name'));
    }

    private function assertEvent(Request $request, Scope $scope, Event $event): void
    {
        $actor = $this->context->actor($request);
        abort_unless($event->scope_id === $scope->id, 404);
        abort_if($event->visibility === 'private' && $event->created_by !== $actor->id && $scope->owner_id !== $actor->id, 404);
        if ($event->project_id) {
            $event->loadMissing('project');
            abort_unless($this->access->canAccessProject($actor, $scope, $event->project), 404);
        }
    }
}
