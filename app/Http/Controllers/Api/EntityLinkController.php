<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreEntityLinkRequest;
use App\Http\Resources\EntityLinkResource;
use App\Models\Book;
use App\Models\BookPage;
use App\Models\EntityLink;
use App\Models\Project;
use App\Models\Scope;
use App\Models\Task;
use App\Services\ContractorAccessService;
use App\Services\ContractorContext;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class EntityLinkController extends Controller
{
    public function __construct(private readonly ContractorAccessService $access, private readonly ContractorContext $context) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, Scope $scope): AnonymousResourceCollection
    {
        $query = $scope->entityLinks()->with(['source', 'target'])->latest();
        if ($request->filled(['subject_type', 'subject_id'])) {
            $type = (string) $request->query('subject_type');
            $id = (string) $request->query('subject_id');
            $query->where(fn ($links) => $links
                ->where(fn ($side) => $side->where('source_type', $type)->where('source_id', $id))
                ->orWhere(fn ($side) => $side->where('target_type', $type)->where('target_id', $id)));
        }

        return EntityLinkResource::collection($query->paginate(100));
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
            $entity = $model::query()->find($data["{$side}_id"]);
            $book = $entity instanceof Book ? $entity : ($entity instanceof BookPage ? $entity->book : null);
            if ($book !== null) {
                abort_unless($this->access->canAccessBook($this->context->actor($request), $scope, $book->loadMissing('project')), 403, 'This book is outside the contractor access boundary.');
            }
            $project = $entity instanceof Project ? $entity : ($entity instanceof Task ? $entity->project : null);
            if ($project !== null) {
                abort_unless($this->access->allows($this->context->actor($request), $scope, 'task.view', $project), 403, 'This entity is outside the contractor project boundary.');
            }
            if ($entity instanceof Task && $entity->project_id === null) {
                abort_unless($this->access->canAccessUnprojected($this->context->actor($request), $scope), 403, 'Unprojected tasks are outside the contractor access boundary.');
            }
        }
        $link = $scope->entityLinks()->create([...$data, 'created_by' => $request->user()->id]);

        return new EntityLinkResource($link->load(['source', 'target']));
    }

    public function destroy(Scope $scope, EntityLink $link): Response
    {
        abort_unless($link->scope_id === $scope->id, 404);
        $link->delete();

        return response()->noContent();
    }

    /**
     * Display the specified resource.
     */
}
