<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreEventSectionRequest;
use App\Http\Resources\EventSectionResource;
use App\Models\Scope;
use App\Services\ContentStructureService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EventSectionController extends Controller
{
    public function __construct(private readonly ContentStructureService $structure) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, Scope $scope): AnonymousResourceCollection
    {
        $actor = $this->structure->authorize($request, $scope, 'eventor');

        return EventSectionResource::collection($this->structure->sections($scope, $actor));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreEventSectionRequest $request, Scope $scope): EventSectionResource
    {
        $section = $this->structure->save($request, $scope, 'eventor', 'sections', $request->validated());

        return new EventSectionResource($section);
    }

    /**
     * Display the specified resource.
     */
}
