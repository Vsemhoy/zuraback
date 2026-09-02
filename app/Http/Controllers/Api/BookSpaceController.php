<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreBookSpaceRequest;
use App\Models\BookSpace;
use App\Models\Scope;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class BookSpaceController extends Controller
{
    public function index(Scope $scope): AnonymousResourceCollection
    {
        return JsonResource::collection($scope->bookSpaces()->withCount('books')->orderBy('sort_order')->orderBy('title')->get());
    }

    public function store(StoreBookSpaceRequest $request, Scope $scope): JsonResource
    {
        $data = $request->validated();
        $data['sort_order'] = ((int) $scope->bookSpaces()->max('sort_order')) + 1000;

        return new JsonResource($scope->bookSpaces()->create([...$data, 'created_by' => $request->user()->id]));
    }

    public function update(Request $request, Scope $scope, BookSpace $bookSpace): JsonResource
    {
        $this->assertInScope($scope, $bookSpace);
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'visibility' => ['sometimes', 'in:private,scope,public'],
        ]);
        $bookSpace->update($data);

        return new JsonResource($bookSpace->fresh()->loadCount('books'));
    }

    public function reorder(Request $request, Scope $scope): AnonymousResourceCollection
    {
        $data = $request->validate([
            'space_ids' => ['required', 'array'],
            'space_ids.*' => ['required', 'ulid', 'distinct'],
        ]);
        $spaces = $scope->bookSpaces()->whereIn('id', $data['space_ids'])->get()->keyBy('id');
        abort_unless($spaces->count() === count($data['space_ids']), 404);

        DB::transaction(function () use ($data, $spaces): void {
            foreach ($data['space_ids'] as $index => $spaceId) {
                $spaces->get($spaceId)->update(['sort_order' => ($index + 1) * 1000]);
            }
        });

        return $this->index($scope);
    }

    public function destroy(Scope $scope, BookSpace $bookSpace): Response
    {
        $this->assertInScope($scope, $bookSpace);
        DB::transaction(function () use ($bookSpace): void {
            $bookSpace->books()->update(['space_id' => null]);
            $bookSpace->delete();
        });

        return response()->noContent();
    }

    private function assertInScope(Scope $scope, BookSpace $bookSpace): void
    {
        abort_unless($bookSpace->scope_id === $scope->id, 404);
    }
}
