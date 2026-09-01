<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreFactRequest;
use App\Http\Requests\Api\UpdateFactRequest;
use App\Http\Resources\FactResource;
use App\Models\Fact;
use App\Models\Scope;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FactController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Scope $scope): AnonymousResourceCollection
    {
        return FactResource::collection($scope->facts()->latest()->get());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreFactRequest $request, Scope $scope): FactResource
    {
        $fact = $scope->facts()->create([...$request->validated(), 'created_by' => $request->user()->id]);

        return new FactResource($fact);
    }

    /**
     * Display the specified resource.
     */
    public function show(Scope $scope, Fact $fact): FactResource
    {
        abort_unless($fact->scope_id === $scope->id, 404);

        return new FactResource($fact);
    }

    public function update(UpdateFactRequest $request, Scope $scope, Fact $fact): FactResource
    {
        abort_unless($fact->scope_id === $scope->id, 404);
        $fact->update($request->validated());
        return new FactResource($fact->fresh());
    }
}
