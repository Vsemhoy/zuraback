<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\EventCalendarRequest;
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
use App\Services\EventRecurrenceService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class EventController extends Controller
{
    public function __construct(private readonly ContractorAccessService $access, private readonly ContractorContext $context, private readonly EventRecurrenceService $recurrence) {}

    public function index(Request $request, Scope $scope): AnonymousResourceCollection
    {
        $events = $this->filteredQuery($request, $scope);
        if ($request->filled('from')) {
            $events->where(fn (Builder $query) => $query->where('starts_at', '>=', $request->query('from'))->orWhere('occurred_at', '>=', $request->query('from')));
        }
        if ($request->filled('until')) {
            $events->where(fn (Builder $query) => $query->where('starts_at', '<=', $request->query('until'))->orWhere('occurred_at', '<=', $request->query('until')));
        }
        $perPage = min(max((int) $request->integer('per_page', 20), 1), 200);

        return EventResource::collection($events
            ->with(['type:id,code,name,color,background_color', 'project:id,title,key,color,event_comments_enabled', 'creator:id,name,type', 'requester:id,name,type', 'recurrenceUser:id,name,status,is_active,deleted_at'])
            ->withCount('comments')->orderByDesc('is_pinned')->orderByRaw('COALESCE(starts_at, occurred_at, created_at) DESC')->paginate($perPage));
    }

    public function calendar(EventCalendarRequest $request, Scope $scope): AnonymousResourceCollection
    {
        $data = $request->validated();
        $timezone = $data['timezone'] ?? 'UTC';
        $from = CarbonImmutable::parse($data['from'], $timezone)->startOfDay()->utc();
        $until = CarbonImmutable::parse($data['until'], $timezone)->endOfDay()->utc();
        $events = $this->filteredQuery($request, $scope)
            ->where(function (Builder $query) use ($from, $until): void {
                $query->where(fn (Builder $repeating) => $repeating->whereNotNull('recurrence_frequency')->where('starts_at', '<=', $until))
                    ->orWhere(fn (Builder $single) => $single->whereNull('recurrence_frequency')
                        ->whereRaw('COALESCE(starts_at, occurred_at, created_at) BETWEEN ? AND ?', [$from->format('Y-m-d H:i:s'), $until->format('Y-m-d H:i:s')]));
            })
            ->where(function (Builder $query) use ($scope): void {
                $query->whereNull('recurrence_frequency')->orWhereNull('recurrence_user_id')
                    ->orWhereHas('recurrenceUser', fn (Builder $person) => $person->whereNull('deleted_at')->where('is_active', true)->where('status', '!=', 'blocked')
                        ->where(fn (Builder $member) => $member->whereKey($scope->owner_id)
                            ->orWhereHas('scopeMemberships', fn (Builder $membership) => $membership->where('scope_id', $scope->id)->where('is_active', true))));
            })
            ->with(['type:id,code,name,color,background_color', 'project:id,title,key,color,event_comments_enabled', 'creator:id,name,type', 'requester:id,name,type', 'recurrenceUser:id,name,status,is_active,deleted_at'])
            ->withCount('comments')->orderBy('id')->paginate($data['per_page'] ?? 200);
        $events->setCollection($events->getCollection()->flatMap(fn (Event $event) => $this->recurrence->occurrences($event, $from, $until))
            ->sortBy('occurrence_start')->values());

        return EventResource::collection($events);
    }

    private function filteredQuery(Request $request, Scope $scope): Builder
    {
        $actor = $this->context->actor($request);
        $projectIds = $this->access->constrainProjects($scope->projects()->getQuery(), $actor, $scope)->where('show_in_eventor', true)->pluck('id');
        $events = $scope->events()->getQuery()->where(function (Builder $query) use ($projectIds, $actor, $scope): void {
            $query->whereIn('project_id', $projectIds);
            if ($this->access->canAccessUnprojected($actor, $scope)) {
                $query->orWhereNull('project_id');
            }
        });
        if ($scope->owner_id !== $actor->id) {
            $events->where(fn (Builder $query) => $query->where('visibility', '!=', 'private')->orWhere('created_by', $actor->id));
        }

        foreach (['project_id', 'type_id', 'created_by', 'requester_id', 'importance', 'status'] as $filter) {
            if ($request->filled($filter)) {
                if ($filter === 'type_id' && $scope->eventTypes()->whereKey($request->query($filter))->where('code', 'none')->exists()) {
                    $events->where(fn (Builder $query) => $query->whereNull('type_id')->orWhere('type_id', $request->query($filter)));

                    continue;
                }
                $events->where($filter, $request->query($filter));
            }
        }
        if ($request->boolean('pinned')) {
            $events->where('is_pinned', true);
        }
        if ($request->filled('q')) {
            $needle = '%'.trim((string) $request->query('q')).'%';
            $events->where(fn (Builder $query) => $query->where('title', 'like', $needle)->orWhere('content', 'like', $needle)
                ->orWhere('location', 'like', $needle)->orWhereHas('comments', fn (Builder $comments) => $comments->where('content', 'like', $needle)));
        }

        return $events;
    }

    public function store(StoreEventRequest $request, Scope $scope): EventResource
    {
        $data = $this->recurrence->prepare($request->validated());
        $actor = $this->context->actor($request);
        $this->assertReferences($scope, $data, $actor);
        $data['visibility'] ??= 'scope';
        $data['occurred_at'] ??= $data['starts_at'] ?? now();
        $event = $scope->events()->create([...$data, 'created_by' => $actor->id]);
        $this->log($request, $scope, $event, 'event.created', null, $event->only(['title', 'project_id', 'type_id', 'importance', 'starts_at', 'recurrence_frequency', 'recurrence_until', 'recurrence_timezone', 'recurrence_user_id']));

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
        $data = $this->recurrence->prepare($request->validated(), $event);
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
        if (! empty($data['recurrence_user_id'])) {
            $person = User::query()->whereKey($data['recurrence_user_id'])->whereIn('type', ['real', 'virtual'])->where('is_active', true)->where('status', '!=', 'blocked')->first();
            abort_unless($person && ($scope->owner_id === $person->id || $scope->members()->where('user_id', $person->id)->where('is_active', true)->exists()), 422, 'Сотрудник недоступен в этом пространстве.');
        }
        if (! empty($data['type_id'])) {
            abort_unless($scope->eventTypes()->whereKey($data['type_id'])->exists(), 422, 'Invalid type_id for this scope.');
        }
        if (! empty($data['section_id'])) {
            abort_unless($scope->eventSections()->whereKey($data['section_id'])->exists(), 422, 'Invalid section_id for this scope.');
        }
        if (! empty($data['parent_id'])) {
            abort_unless($scope->events()->whereKey($data['parent_id'])->exists(), 422, 'Invalid parent_id for this scope.');
        }
        if (! empty($data['requester_id'])) {
            abort_unless($scope->members()->where('user_id', $data['requester_id'])->where('is_active', true)->exists(), 422, 'Invalid requester_id for this scope.');
        }
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
        return $event->load(['type:id,code,name,color,background_color', 'project:id,title,key,color,event_comments_enabled', 'creator:id,name,type', 'requester:id,name,type', 'recurrenceUser:id,name,status,is_active,deleted_at'])->loadCount('comments');
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
