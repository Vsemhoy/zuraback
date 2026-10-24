<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreFactRequest;
use App\Http\Requests\Api\UpdateFactRequest;
use App\Http\Resources\FactResource;
use App\Models\ActivityLog;
use App\Models\Fact;
use App\Models\Scope;
use App\Services\ContractorAccessService;
use App\Services\ContractorContext;
use App\Services\PrivateRecordAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class FactController extends Controller
{
    public function __construct(private readonly PrivateRecordAccessService $access, private readonly ContractorContext $context) {}

    public function options(Request $request, Scope $scope): JsonResponse
    {
        $actor = $this->context->actor($request);

        return response()->json(['data' => [
            'projects' => $this->access->projects($actor, $scope, 'fact.view')->orderBy('sort_order')->orderBy('title')->get(['id', 'title', 'key']),
            'can_create' => app(ContractorAccessService::class)->allows($actor, $scope, 'fact.write'),

        ]]);
    }

    public function index(Request $request, Scope $scope): AnonymousResourceCollection
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:200'], 'kind' => ['nullable', 'string', 'max:32'], 'format' => ['nullable', 'string', 'max:24'], 'project_id' => ['nullable', 'ulid'], 'visibility' => ['nullable', 'in:private,scope,project'], 'pinned' => ['nullable', 'boolean'], 'expert' => ['nullable', 'boolean'], 'trash' => ['nullable', 'boolean'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $query = $this->access->query(Fact::class, $this->context->actor($request), $scope);
        if ($request->boolean('trash')) {
            $query->onlyTrashed();
        }
        foreach (['kind', 'format', 'project_id', 'visibility'] as $field) {
            if (! empty($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if ($request->boolean('pinned')) {
            $query->where('is_pinned', true);
        }
        if ($request->boolean('expert')) {
            $query->where('is_expert', true);
        }
        if (! empty($data['q'])) {
            $pattern = '%'.$data['q'].'%';
            $query->where(fn (Builder $q) => $q->where('label', 'like', $pattern)->orWhere('context', 'like', $pattern)->orWhere('search_keywords', 'like', $pattern)->orWhere(fn (Builder $q) => $q->where('is_sensitive', false)->where('value', 'like', $pattern)));
        }

        return FactResource::collection($query->orderByDesc('is_pinned')->orderBy('sort_order')->orderBy('label')->orderBy('id')->paginate($data['per_page'] ?? 50));
    }

    public function store(StoreFactRequest $request, Scope $scope): FactResource
    {
        $actor = $this->context->actor($request);
        $this->access->validate($actor, $scope, Fact::class, $request->validated());
        $fact = DB::transaction(function () use ($request, $scope, $actor): Fact {
            $fact = $scope->facts()->create([...$request->validated(), 'created_by' => $actor->id]);
            $this->audit($request, $scope, $fact, 'created', array_keys($request->validated()));

            return $fact;
        });

        return new FactResource($fact);
    }

    public function show(Request $request, Scope $scope, Fact $fact): FactResource
    {
        abort_unless($this->access->read($this->context->actor($request), $scope, $fact), 404);

        return new FactResource($fact);
    }

    public function update(UpdateFactRequest $request, Scope $scope, Fact $fact): FactResource
    {
        return DB::transaction(function () use ($request, $scope, $fact): FactResource {
            $fact = Fact::whereKey($fact->id)->lockForUpdate()->firstOrFail();
            $actor = $this->context->actor($request);
            abort_unless($this->access->read($actor, $scope, $fact), 404);
            abort_unless($this->access->write($actor, $scope, $fact), 403);
            $data = $request->validated();
            abort_unless($fact->revision === $data['revision'], 409, 'Факт уже изменён. Обновите запись, чтобы не потерять изменения.');
            $this->access->validate($actor, $scope, Fact::class, $data, $fact);
            if ($fact->is_sensitive && array_intersect(array_keys($data), ['value', 'is_sensitive'])) {
                abort_unless($this->access->reveal($actor, $scope, $fact), 403, 'Для изменения скрытого значения требуется fact.reveal.');
            }
            $fact->update([...$data, 'revision' => $fact->revision + 1]);
            $this->audit($request, $scope, $fact, 'updated', array_keys($data));

            return new FactResource($fact);
        });
    }

    public function reveal(Request $request, Scope $scope, Fact $fact): JsonResponse
    {
        $actor = $this->context->actor($request);
        abort_unless($this->access->read($actor, $scope, $fact), 404);
        abort_unless($this->access->reveal($actor, $scope, $fact), 403);
        $this->audit($request, $scope, $fact, 'revealed', []);

        return response()->json(['data' => ['value' => $fact->value, 'revision' => $fact->revision]])->withHeaders(['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']);
    }

    public function destroy(Request $request, Scope $scope, Fact $fact): Response
    {
        $this->setDeleted($request, $scope, $fact->id, true);

        return response()->noContent();
    }

    public function restore(Request $request, Scope $scope, string $fact): FactResource
    {
        return new FactResource($this->setDeleted($request, $scope, $fact, false));
    }

    private function setDeleted(Request $request, Scope $scope, string $id, bool $deleted): Fact
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);

        return DB::transaction(function () use ($request, $scope, $id, $deleted, $data): Fact {
            $fact = Fact::withTrashed()->whereKey($id)->lockForUpdate()->firstOrFail();
            $actor = $this->context->actor($request);
            abort_unless($this->access->read($actor, $scope, $fact), 404);
            abort_unless($this->access->manage($actor, $scope, $fact), 403);
            abort_unless($fact->revision === $data['revision'], 409, 'Факт уже изменён.');
            $fact->update(['revision' => $fact->revision + 1]);
            if ($deleted) {
                $fact->delete();
            } else {
                $fact->restore();
            }
            $this->audit($request, $scope, $fact, $deleted ? 'deleted' : 'restored', []);

            return $fact;
        });
    }

    private function audit(Request $request, Scope $scope, Fact $fact, string $action, array $fields): void
    {
        ActivityLog::create(['scope_id' => $scope->id, 'actor_id' => $this->context->actor($request)->id, 'subject_type' => 'fact', 'subject_id' => $fact->id, 'action' => 'fact.'.$action, 'after' => ['revision' => $fact->revision, 'fields' => $fields], 'context' => [...$this->context->auditMetadata($request), 'values_redacted' => true]]);
    }
}
