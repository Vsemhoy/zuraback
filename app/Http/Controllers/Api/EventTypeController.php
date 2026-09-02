<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreEventTypeRequest;
use App\Http\Resources\EventTypeResource;
use App\Models\Scope;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class EventTypeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Scope $scope): AnonymousResourceCollection
    {
        $defaults = [
            ['none', 'Без типа', '#7b8798', '#eef1f5'], ['request', 'Заявка', '#9b5c12', '#fff1dc'],
            ['action', 'Действие', '#176b51', '#e3f6ef'], ['event', 'Событие', '#2d6cdf', '#e7f0fd'],
            ['state', 'Состояние', '#7048a8', '#f0e8fb'], ['note', 'Заметка', '#b04d73', '#fae8ef'],
            ['synopsis', 'Конспект', '#456276', '#e7eef2'],
        ];
        foreach ($defaults as $index => [$code, $name, $color, $background]) {
            $scope->eventTypes()->firstOrCreate(['code' => $code], ['id' => (string) Str::ulid(), 'name' => $name, 'color' => $color, 'background_color' => $background, 'sort_order' => $index * 1000, 'is_default' => $code === 'none']);
        }
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
