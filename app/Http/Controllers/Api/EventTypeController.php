<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreEventTypeRequest;
use App\Http\Resources\EventTypeResource;
use App\Models\Scope;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EventTypeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Scope $scope): AnonymousResourceCollection
    {
        return EventTypeResource::collection($scope->eventTypes()->orderBy('sort_order')->get());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreEventTypeRequest $request, Scope $scope): EventTypeResource
    {
        return new EventTypeResource($scope->eventTypes()->create($request->validated()));
    }

    /**
     * Display the specified resource.
     */
}
