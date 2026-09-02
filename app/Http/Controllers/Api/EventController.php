<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreEventRequest;
use App\Http\Requests\Api\UpdateEventRequest;
use App\Http\Resources\EventResource;
use App\Models\ActivityLog;
use App\Models\Event;
use App\Models\Project;
use App\Models\Scope;
use App\Models\User;
use App\Services\ContractorAccessService;
use App\Services\ContractorContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class EventController extends Controller
{
    public function __construct(private readonly ContractorAccessService $access, private readonly ContractorContext $context) {}

    public function index(Request $request, Scope $scope): AnonymousResourceCollection
    {
        $actor = $this->context->actor($request);
        $projectIds = $this->access->constrainProjects($scope->projects()->getQuery(), $actor, $scope)->where('show_in_eventor', true)->pluck('id');
        $events = $scope->events()->getQuery()->where(fn (Builder $query) => $query->whereNull('project_id')->orWhereIn('project_id', $projectIds));
        if ($scope->owner_id !== $actor->id) {
            $events->where(fn (Builder $query) => $query->where('visibility', '!=', 'private')->orWhere('created_by', $actor->id));
        }

        foreach (['project_id', 'type_id', 'created_by', 'requester_id', 'importance', 'status'] as $filter) {
            if ($request->filled($filter)) $events->where($filter, $request->query($filter));
        }
        if ($request->boolean('pinned')) $events->where('is_pinned', true);
        if ($request->filled('from')) $events->where(fn (Builder $query) => $query->where('starts_at', '>=', $request->query('from'))->orWhere('occurred_at', '>=', $request->query('from')));
        if ($request->filled('until')) $events->where(fn (Builder $query) => $query->where('starts_at', '<=', $request->query('until'))->orWhere('occurred_at', '<=', $request->query('until')));
        if ($request->filled('q')) {
            $needle = '%'.trim((string) $request->query('q')).'%';
            $events->where(fn (Builder $query) => $query->where('title', 'like', $needle)->orWhere('content', 'like', $needle)
                ->orWhere('location', 'like', $needle)->orWhereHas('comments', fn (Builder $comments) => $comments->where('content', 'like', $needle)));
        }

        $perPage = min(max((int) $request->integer('per_page', 20), 1), 200);
        return EventResource::collection($events
            ->with(['type:id,code,name,color,background_color', 'project:id,title,key,color,event_comments_enabled', 'creator:id,name,type', 'requester:id,name,type'])
            ->withCount('comments')->orderByDesc('is_pinned')->orderByRaw('COALESCE(starts_at, occurred_at, created_at) DESC')->paginate($perPage));
    }

    public function store(StoreEventRequest $request, Scope $scope): EventResource
    {
        $data = $request->validated();
        $actor = $this->context->actor($request);
        $this->assertReferences($scope, $data, $actor);
        $data['visibility'] ??= 'scope';
        $data['occurred_at'] ??= $data['starts_at'] ?? now();
        $event = $scope->events()->create([...$data, 'created_by' => $actor->id]);
        $this->log($request, $scope, $event, 'event.created', null, $event->only(['title', 'project_id', 'type_id', 'importance', 'starts_at']));
        return new EventResource($this->loaded($event));
    }

    public function show(Request $request, Scope $scope, Event $event): EventResource
    {
        $this->assertEvent($request, $scope, $event);
        return new EventResource($this->loaded($event));
    }

    public function update(UpdateEventRequest $request, Scope $scope, Event $event): EventResource
    {
        $actor = $this->context->actor($request);
        $this->assertEvent($request, $scope, $event);
        $data = $request->validated();
        abort_if($event->is_locked && $event->created_by !== $actor->id && $scope->owner_id !== $actor->id, 423, 'The event is locked.');
        if (array_key_exists('visibility', $data) || array_key_exists('is_locked', $data)) {
            abort_unless($event->created_by === $actor->id || $scope->owner_id === $actor->id, 403, 'Only the event author or scope owner can change access settings.');
        }
        $this->assertReferences($scope, $data, $actor);
        abort_if(($data['parent_id'] ?? null) === $event->id, 422, 'An event cannot be its own parent.');
        $before = $event->only(array_keys($data));
        $event->update($data);
        $this->log($request, $scope, $event, 'event.updated', $before, $event->fresh()->only(array_keys($data)));
        return new EventResource($this->loaded($event->fresh()));
    }

    public function destroy(Request $request, Scope $scope, Event $event): Response
    {
        $actor = $this->context->actor($request);
        $this->assertEvent($request, $scope, $event);
        abort_unless($event->created_by === $actor->id || $scope->owner_id === $actor->id, 403, 'Only the event author or scope owner can delete it.');
        $before = $event->only(['title', 'project_id', 'type_id', 'starts_at']);
        $event->comments()->delete();
        $event->delete();
        $this->log($request, $scope, $event, 'event.deleted', $before, null);
        return response()->noContent();
    }

    private function assertReferences(Scope $scope, array $data, User $actor): void
    {
        if (! empty($data['type_id'])) abort_unless($scope->eventTypes()->whereKey($data['type_id'])->exists(), 422, 'Invalid type_id for this scope.');
        if (! empty($data['section_id'])) abort_unless($scope->eventSections()->whereKey($data['section_id'])->exists(), 422, 'Invalid section_id for this scope.');
        if (! empty($data['parent_id'])) abort_unless($scope->events()->whereKey($data['parent_id'])->exists(), 422, 'Invalid parent_id for this scope.');
        if (! empty($data['requester_id'])) abort_unless($scope->members()->where('user_id', $data['requester_id'])->where('is_active', true)->exists(), 422, 'Invalid requester_id for this scope.');
        if (! empty($data['project_id'])) {
            $project = Project::query()->find($data['project_id']);
            abort_unless($project && $this->access->canAccessProject($actor, $scope, $project), 422, 'Invalid project_id for this scope.');
        }
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

    private function loaded(Event $event): Event
    {
        return $event->load(['type:id,code,name,color,background_color', 'project:id,title,key,color,event_comments_enabled', 'creator:id,name,type', 'requester:id,name,type'])->loadCount('comments');
    }

    private function log(Request $request, Scope $scope, Event $event, string $action, ?array $before, ?array $after): void
    {
        ActivityLog::query()->create([
            'scope_id' => $scope->id, 'actor_id' => $this->context->actor($request)->id, 'subject_type' => 'event', 'subject_id' => $event->id,
            'action' => $action, 'before' => $before, 'after' => $after, 'context' => $this->context->auditMetadata($request),
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);
    }
}
