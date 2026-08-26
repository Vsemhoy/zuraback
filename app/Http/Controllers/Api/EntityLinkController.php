<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreEntityLinkRequest;
use App\Http\Resources\EntityLinkResource;
use App\Models\Scope;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Database\Eloquent\Relations\Relation;

class EntityLinkController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Scope $scope): AnonymousResourceCollection
    {
        return EntityLinkResource::collection($scope->entityLinks()->latest()->paginate());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreEntityLinkRequest $request, Scope $scope): EntityLinkResource
    {
        $data = $request->validated();
        foreach (['source', 'target'] as $side) {
            $model = Relation::getMorphedModel($data["{$side}_type"]);
            abort_unless($model !== null && $model::query()->whereKey($data["{$side}_id"])->where('scope_id', $scope->id)->exists(), 422, "Invalid {$side} for this scope.");
        }
        $link = $scope->entityLinks()->create([...$data, 'created_by' => $request->user()->id]);

        return new EntityLinkResource($link);
    }

    /**
     * Display the specified resource.
     */
}
