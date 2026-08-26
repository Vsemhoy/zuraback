<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreScopeRequest;
use App\Http\Resources\ScopeResource;
use App\Models\Scope;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ScopeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $scopes = Scope::query()
            ->where('owner_id', $request->user()->id)
            ->orWhereHas('members', fn ($query) => $query->where('user_id', $request->user()->id)->where('is_active', true))
            ->latest()
            ->paginate();

        return ScopeResource::collection($scopes);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreScopeRequest $request): ScopeResource
    {
        $scope = DB::transaction(function () use ($request): Scope {
            $scope = Scope::query()->create([...$request->validated(), 'owner_id' => $request->user()->id]);
            $scope->members()->create(['user_id' => $request->user()->id, 'role' => 'owner', 'joined_at' => now()]);

            return $scope;
        });

        return new ScopeResource($scope);
    }

    /**
     * Display the specified resource.
     */
    public function show(Scope $scope): ScopeResource
    {
        return new ScopeResource($scope);
    }
}
