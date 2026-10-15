<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\DarklogCommentRequest;
use App\Http\Requests\Api\DarklogKpiRequest;
use App\Http\Requests\Api\DarklogLinkRequest;
use App\Http\Requests\Api\DarklogRequest;
use App\Models\Comment;
use App\Models\Darklog;
use App\Models\DarklogKpiLink;
use App\Models\DarklogLink;
use App\Models\Department;
use App\Models\Kpi;
use App\Models\Scope;
use App\Models\User;
use App\Services\ContractorAccessService;
use App\Services\ContractorContext;
use App\Services\DarklogLinkService;
use App\Services\DarklogService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DarklogController extends Controller
{
    public function __construct(private readonly DarklogService $darklogs, private readonly DarklogLinkService $links, private readonly ContractorAccessService $access, private readonly ContractorContext $context) {}

    public function index(Request $request, Scope $scope): JsonResponse
    {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m', 'regex:/^20[0-9]{2}-(0[1-9]|1[0-2])$/'],
            'timezone' => ['sometimes', 'timezone'], 'status' => ['nullable', Rule::in(['active', ...Darklog::STATUSES])],
            'project_id' => ['nullable', 'ulid'], 'department_id' => ['nullable', 'ulid'], 'responsible_id' => ['nullable', 'ulid'],
            'severity' => ['nullable', Rule::in(Darklog::SEVERITIES)], 'kind' => ['nullable', Rule::in(Darklog::KINDS)],
            'q' => ['nullable', 'string', 'max:150'], 'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'subject_type' => ['required_with:subject_id', Rule::in(array_keys(DarklogLinkService::TARGETS))], 'subject_id' => ['required_with:subject_type', 'ulid'],
        ]);
        $from = CarbonImmutable::createFromFormat('!Y-m', $data['month'], $data['timezone'] ?? 'UTC')->startOfMonth()->utc();
        $until = $from->setTimezone($data['timezone'] ?? 'UTC')->addMonth()->utc();
        $actor = $this->context->actor($request);
        if (! empty($data['subject_id'])) {
            abort_unless($this->links->resolve($actor, $scope, $data['subject_type'], $data['subject_id']), 404);
        }
        $query = $this->darklogs->visible($actor, $scope);
        if (empty($data['subject_id'])) {
            $query->where(function (Builder $q) use ($from, $until): void {
                $q->whereHas('periods', fn (Builder $p) => $p->where('starts_at', '<', $until)->where(fn (Builder $end) => $end->whereNull('ends_at')->orWhere('ends_at', '>', $from)))
                    ->orWhere(fn (Builder $empty) => $empty->doesntHave('periods')->where('created_at', '>=', $from)->where('created_at', '<', $until));
            });
        }
        foreach (['project_id', 'department_id', 'responsible_id', 'severity', 'kind'] as $field) {
            if (! empty($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (! empty($data['status'])) {
            $data['status'] === 'active' ? $query->whereNotIn('status', ['closed', 'unconfirmed']) : $query->where('status', $data['status']);
        }
        if (! empty($data['q'])) {
            $query->where(function (Builder $q) use ($data): void {
                $q->where('title', 'like', '%'.$data['q'].'%')->orWhere('description', 'like', '%'.$data['q'].'%');
                if (preg_match('/^(?:DRK-)?(\d+)$/i', $data['q'], $matches)) {
                    $q->orWhere('number', (int) $matches[1]);
                }
            });
        }
        if (! empty($data['subject_id'])) {
            $query->whereHas('links', fn (Builder $links) => $links->where('target_type', $data['subject_type'])->where('target_id', $data['subject_id']));
        }
        $page = $query->with(['periods', 'links', 'comments.creator:id,name,profile', 'project:id,title,key,color', 'department:id,name,color', 'responsible:id,name,profile', 'creator:id,name,profile'])
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($data['per_page'] ?? 50);

        return response()->json(['data' => [
            'items' => $page->getCollection()->map(fn (Darklog $item): array => $this->darklogs->present($request, $scope, $item))->values(),
            'total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'from' => $from, 'until' => $until,
        ]]);
    }

    public function options(Request $request, Scope $scope): JsonResponse
    {
        $actor = $this->context->actor($request);
        $people = User::query()->where('is_active', true)->where('status', 'active')
            ->where(fn (Builder $q) => $q->whereKey($scope->owner_id)->orWhereHas('scopeMemberships', fn (Builder $m) => $m->where('scope_id', $scope->id)->where('is_active', true)))
            ->orderBy('name')->get(['id', 'name', 'profile', 'is_executor', 'type']);

        return response()->json(['data' => [
            'projects' => $this->access->constrainProjects($scope->projects()->getQuery(), $actor, $scope)->orderBy('sort_order')->get(['id', 'key', 'title', 'color']),
            'departments' => Department::query()->where('scope_id', $scope->id)->orderBy('name')->get(['id', 'name', 'color']),
            'people' => $people->map(fn (User $person): array => [...$this->darklogs->person($person), 'is_executor' => $person->is_executor, 'type' => $person->type]),
            'kpis' => Kpi::query()->where('scope_id', $scope->id)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'kind', 'points']),
            'can_create' => $this->access->allows($actor, $scope, 'task.create'),
            'can_review' => $this->access->allows($actor, $scope, 'contractor.manage'),
        ]]);
    }

    public function show(Request $request, Scope $scope, Darklog $darklog): JsonResponse
    {
        $this->darklogs->authorize($this->context->actor($request), $scope, $darklog);

        return response()->json(['data' => $this->darklogs->present($request, $scope, $darklog, true)]);
    }

    public function store(DarklogRequest $request, Scope $scope): JsonResponse
    {
        $darklog = $this->darklogs->save($request, $scope, new Darklog, $request->validated());

        return $this->show($request, $scope, $darklog)->setStatusCode(201);
    }

    public function update(DarklogRequest $request, Scope $scope, Darklog $darklog): JsonResponse
    {
        $darklog = $this->darklogs->save($request, $scope, $darklog, $request->validated());

        return $this->show($request, $scope, $darklog);
    }

    public function destroy(Request $request, Scope $scope, Darklog $darklog): Response
    {
        $this->darklogs->authorize($this->context->actor($request), $scope, $darklog, 'task.delete');
        DB::transaction(function () use ($request, $scope, $darklog): void {
            $this->darklogs->audit($request, $scope, $darklog, 'darklog.deleted', $darklog->attributesToArray());
            $darklog->delete();
        });

        return response()->noContent();
    }

    public function candidates(Request $request, Scope $scope): JsonResponse
    {
        $data = $request->validate(['type' => ['required', Rule::in(array_keys(DarklogLinkService::TARGETS))], 'q' => ['nullable', 'string', 'max:150']]);

        return response()->json(['data' => $this->links->candidates($this->context->actor($request), $scope, $data['type'], $data['q'] ?? '')]);
    }

    public function storeLink(DarklogLinkRequest $request, Scope $scope, Darklog $darklog): JsonResponse
    {
        $actor = $this->context->actor($request);
        $this->darklogs->authorize($actor, $scope, $darklog, 'task.update');
        $data = $request->validated();
        abort_if($data['target_type'] === 'darklog' && $data['target_id'] === $darklog->id, 422, 'Нельзя связать дарклог с самим собой.');
        abort_unless($this->links->resolve($actor, $scope, $data['target_type'], $data['target_id']), 404);
        DB::transaction(function () use ($request, $scope, $darklog, $actor, $data): void {
            Darklog::query()->whereKey($darklog->id)->lockForUpdate()->firstOrFail();
            $link = $darklog->links()->updateOrCreate(collect($data)->only(['target_type', 'target_id', 'relation'])->all(),
                ['note' => $data['note'] ?? null, 'created_by' => $actor->id]);
            $this->darklogs->audit($request, $scope, $darklog, 'darklog.linked', null, $link->attributesToArray());
        });

        return $this->show($request, $scope, $darklog);
    }

    public function destroyLink(Request $request, Scope $scope, Darklog $darklog, DarklogLink $link): Response
    {
        $this->darklogs->authorize($this->context->actor($request), $scope, $darklog, 'task.update');
        abort_unless($link->darklog_id === $darklog->id, 404);
        DB::transaction(function () use ($request, $scope, $darklog, $link): void {
            $before = $link->attributesToArray();
            $link->delete();
            $this->darklogs->audit($request, $scope, $darklog, 'darklog.unlinked', $before);
        });

        return response()->noContent();
    }

    public function storeComment(DarklogCommentRequest $request, Scope $scope, Darklog $darklog): JsonResponse
    {
        $actor = $this->context->actor($request);
        $this->darklogs->authorize($actor, $scope, $darklog, 'task.update');
        $data = $request->validated();
        if (! empty($data['parent_id'])) {
            $parent = $darklog->comments()->findOrFail($data['parent_id']);
            abort_if($parent->parent_id !== null, 422, 'Допускается один уровень ответов.');
        }
        $darklog->comments()->create([...$data, 'scope_id' => $scope->id, 'created_by' => $actor->id, 'kind' => 'comment']);

        return $this->show($request, $scope, $darklog);
    }

    public function destroyComment(Request $request, Scope $scope, Darklog $darklog, Comment $comment): Response
    {
        $actor = $this->context->actor($request);
        $this->darklogs->authorize($actor, $scope, $darklog, 'task.update');
        abort_unless($comment->commentable_type === 'darklog' && $comment->commentable_id === $darklog->id && $comment->scope_id === $scope->id, 404);
        abort_unless($comment->created_by === $actor->id || $this->access->allows($actor, $scope, 'contractor.manage'), 403);
        DB::transaction(function () use ($request, $scope, $darklog, $comment): void {
            $comment->delete();
            $this->darklogs->audit($request, $scope, $darklog, 'darklog.comment_deleted');
        });

        return response()->noContent();
    }

    public function storeKpi(DarklogKpiRequest $request, Scope $scope, Darklog $darklog): JsonResponse
    {
        $actor = $this->context->actor($request);
        $this->darklogs->authorize($actor, $scope, $darklog, 'task.update');
        $data = $request->validated();
        abort_unless($this->darklogs->member($scope, $data['user_id'], true), 422, 'Выберите исполнителя этого скоупа.');
        $data['effective_from'] = CarbonImmutable::parse($data['effective_from'])->utc()->format('Y-m-d H:i:s');
        $data['effective_until'] = empty($data['effective_until']) ? null : CarbonImmutable::parse($data['effective_until'])->utc()->format('Y-m-d H:i:s');
        DB::transaction(function () use ($request, $scope, $darklog, $actor, $data): void {
            Darklog::query()->whereKey($darklog->id)->lockForUpdate()->firstOrFail();
            $existing = $darklog->kpiLinks()->where('user_id', $data['user_id'])->where('kpi_id', $data['kpi_id'])->first();
            $canReview = $this->access->allows($actor, $scope, 'contractor.manage');
            abort_unless($canReview || ($data['decision'] === 'pending' && ($existing === null || $existing->decision === 'pending')), 403, 'Применимость KPI подтверждает управляющий сотрудниками.');
            $darklog->kpiLinks()->updateOrCreate(['user_id' => $data['user_id'], 'kpi_id' => $data['kpi_id']],
                [...$data, 'created_by' => $existing?->created_by ?? $actor->id,
                    'reviewed_by' => $data['decision'] === 'pending' ? null : $actor->id, 'reviewed_at' => $data['decision'] === 'pending' ? null : now()]);
            $this->darklogs->audit($request, $scope, $darklog, 'darklog.kpi_reviewed', $existing?->attributesToArray(), $data);
        });

        return $this->show($request, $scope, $darklog);
    }

    public function destroyKpi(Request $request, Scope $scope, Darklog $darklog, DarklogKpiLink $allocation): Response
    {
        $actor = $this->context->actor($request);
        $this->darklogs->authorize($actor, $scope, $darklog, 'task.update');
        abort_unless($allocation->darklog_id === $darklog->id, 404);
        abort_unless($this->access->allows($actor, $scope, 'contractor.manage'), 403);
        DB::transaction(function () use ($request, $scope, $darklog, $allocation): void {
            $this->darklogs->audit($request, $scope, $darklog, 'darklog.kpi_removed', $allocation->attributesToArray());
            $allocation->delete();
        });

        return response()->noContent();
    }
}
