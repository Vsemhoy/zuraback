<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller; use App\Http\Requests\Api\StoreBookSpaceRequest; use App\Models\Scope; use Illuminate\Http\Resources\Json\AnonymousResourceCollection; use Illuminate\Http\Resources\Json\JsonResource;
class BookSpaceController extends Controller {
 public function index(Scope $scope): AnonymousResourceCollection { return JsonResource::collection($scope->bookSpaces()->withCount('books')->orderBy('sort_order')->get()); }
 public function store(StoreBookSpaceRequest $request, Scope $scope): JsonResource { return new JsonResource($scope->bookSpaces()->create([...$request->validated(),'created_by'=>$request->user()->id])); }
}
