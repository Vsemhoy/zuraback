<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreEventRequest;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Models\Scope;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EventController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Scope $scope): AnonymousResourceCollection
    {
        return EventResource::collection($scope->events()->with(['type:id,name,color', 'section:id,name'])->latest('occurred_at')->paginate());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreEventRequest $request, Scope $scope): EventResource
    {
        $data = $request->validated();
        foreach (['type_id' => 'eventTypes', 'section_id' => 'eventSections', 'parent_id' => 'events'] as $key => $relation) {
            if (isset($data[$key])) {
                abort_unless($scope->{$relation}()->whereKey($data[$key])->exists(), 422, "Invalid {$key} for this scope.");
            }
        }
        $event = $scope->events()->create([...$data, 'created_by' => $request->user()->id]);

        return new EventResource($event);
    }

    /**
     * Display the specified resource.
     */
    public function show(Scope $scope, Event $event): EventResource
    {
        abort_unless($event->scope_id === $scope->id, 404);

        return new EventResource($event->load(['type:id,name,color', 'section:id,name']));
    }
}
