<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PlanItemRequest;
use App\Models\ActivityLog;
use App\Models\PlanItem;
use App\Models\Scope;
use App\Models\User;
use App\Services\ContractorContext;
use App\Services\PlanItemService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class PlanItemController extends Controller
{
    public function __construct(private readonly PlanItemService $plans, private readonly ContractorContext $context) {}

    public function index(Request $request, Scope $scope): JsonResource
    {
        $data = $request->validate(['year' => ['required', 'integer', 'between:2000,2099'], 'month' => ['nullable', 'date_format:Y-m'],
            'assignee_id' => ['nullable', 'ulid'], 'project_id' => ['nullable', 'ulid'], 'status' => ['nullable', 'in:open,done'], 'q' => ['nullable', 'string', 'max:150']]);
        $actor = $this->context->actor($request);
        $query = $this->plans->visible($actor, $scope)->whereBetween('month', [$data['year'].'-01', $data['year'].'-12'])
            ->when($data['month'] ?? null, fn ($q, $value) => $q->where('month', $value))
            ->when($data['assignee_id'] ?? null, fn ($q, $value) => $q->where('assignee_id', $value))
            ->when($data['project_id'] ?? null, fn ($q, $value) => $q->where('project_id', $value))
            ->when($data['status'] ?? null, fn ($q, $value) => $value === 'done' ? $q->whereNotNull('completed_at') : $q->whereNull('completed_at'))
            ->when($data['q'] ?? null, fn ($q, $value) => $q->where('title', 'like', '%'.$value.'%'));
        $total = (clone $query)->count();
        $done = (clone $query)->whereNotNull('completed_at')->count();
        $page = $this->plans->details($query, $actor, $scope)->orderBy('month')->orderByDesc('priority')->orderBy('id')->paginate(40);

        return new JsonResource(['items' => $page->getCollection()->map(fn (PlanItem $item): array => $this->plans->row($item)),
            'total' => $total, 'done' => $done, 'page' => $page->currentPage(), 'last_page' => $page->lastPage()]);
    }

    public function options(Request $request, Scope $scope): JsonResource
    {
        $actor = $this->context->actor($request);

        return new JsonResource([
            'projects' => $scope->projects()->whereIn('id', $this->plans->projectIds($actor, $scope))->orderBy('sort_order')->get(['id', 'key', 'title', 'include_in_reports']),
            'people' => User::query()->whereIn('type', ['real', 'virtual'])->where('is_executor', true)
                ->where(fn ($q) => $q->whereKey($scope->owner_id)->orWhereHas('scopeMemberships', fn ($m) => $m->where('scope_id', $scope->id)->where('is_active', true)))
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function candidates(Request $request, Scope $scope): JsonResource
    {
        $data = $request->validate(['project_id' => ['nullable', 'ulid'], 'q' => ['nullable', 'string', 'max:150']]);
        $actor = $this->context->actor($request);
        $query = $this->plans->tasks($actor, $scope)->where('project_id', $data['project_id'] ?? null)
            ->where('status', '!=', 'cancelled')
            ->when($data['q'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->where('title', 'like', '%'.$v.'%')->orWhere('task_key', 'like', '%'.$v.'%')));

        $page = $query->with($this->plans->taskPlanRelations($actor, $scope))
            ->orderByDesc('created_at')->orderBy('id')->paginate(30, ['id', 'scope_id', 'project_id', 'title', 'task_key', 'status']);
        $page->setCollection($page->getCollection()->map(fn ($task): array => $this->plans->taskRow($task)));

        return JsonResource::collection($page);
    }

    public function show(Request $request, Scope $scope, PlanItem $planItem): JsonResource
    {
        $actor = $this->context->actor($request);
        $item = $this->plans->details($this->plans->visible($actor, $scope)->whereKey($planItem->id), $actor, $scope)->firstOrFail();

        return new JsonResource($this->plans->row($item));
    }

    public function store(PlanItemRequest $request, Scope $scope): JsonResource
    {
        $item = $this->plans->save($this->context->actor($request), $scope, new PlanItem, $request->validated(), $request, $this->context->auditMetadata($request));

        return $this->show($request, $scope, $item);
    }

    public function update(PlanItemRequest $request, Scope $scope, PlanItem $planItem): JsonResource
    {
        $this->plans->save($this->context->actor($request), $scope, $planItem, $request->validated(), $request, $this->context->auditMetadata($request));

        return $this->show($request, $scope, $planItem);
    }

    public function destroy(Request $request, Scope $scope, PlanItem $planItem): Response
    {
        $actor = $this->context->actor($request);
        $item = $this->plans->visible($actor, $scope)->whereKey($planItem->id)->firstOrFail();
        $this->plans->authorizeWrite($actor, $scope, $item->project_id);
        DB::transaction(function () use ($item, $actor, $scope, $request): void {
            ActivityLog::query()->create(['scope_id' => $scope->id, 'actor_id' => $actor->id, 'subject_type' => 'plan_item', 'subject_id' => $item->id,
                'action' => 'plan.deleted', 'before' => $item->toArray(), 'context' => $this->context->auditMetadata($request)]);
            $item->delete();
        });

        return response()->noContent();
    }
}
