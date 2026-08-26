<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreEventSectionRequest;
use App\Http\Resources\EventSectionResource;
use App\Models\Scope;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EventSectionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Scope $scope): AnonymousResourceCollection
    {
        return EventSectionResource::collection($scope->eventSections()->orderBy('sort_order')->get());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreEventSectionRequest $request, Scope $scope): EventSectionResource
    {
        $section = $scope->eventSections()->create([...$request->validated(), 'created_by' => $request->user()->id]);

        return new EventSectionResource($section);
    }

    /**
     * Display the specified resource.
     */
}
