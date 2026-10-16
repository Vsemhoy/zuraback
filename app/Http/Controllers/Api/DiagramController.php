<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\DiagramRequest;
use App\Models\ActivityLog;
use App\Models\BookPage;
use App\Models\Diagram;
use App\Models\DiagramAttachment;
use App\Models\DiagramRevision;
use App\Models\FilerFile;
use App\Models\PlanItem;
use App\Models\Scope;
use App\Services\ContractorAccessService;
use App\Services\ContractorContext;
use App\Services\DarklogLinkService;
use App\Services\DiagramAccessService;
use App\Services\FilerAccessService;
use App\Services\PlanItemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DiagramController extends Controller
{
    public function __construct(private readonly DiagramAccessService $access, private readonly ContractorAccessService $contractors,
        private readonly ContractorContext $context, private readonly DarklogLinkService $targets, private readonly FilerAccessService $files, private readonly PlanItemService $plans) {}

    public function index(Request $request, Scope $scope): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'page' => ['sometimes', 'integer', 'min:1'], 'project_id' => ['nullable', 'ulid'],
            'subject_type' => ['required_with:subject_id', Rule::in(array_keys(DiagramAccessService::SUBJECTS))], 'subject_id' => ['required_with:subject_type', 'ulid']]);
        $actor = $this->context->actor($request);
        $query = $this->access->visible($actor, $scope)->with(['creator', 'project']);
        if (! empty($data['q'])) {
            $query->where('title', 'like', '%'.$data['q'].'%');
        }
        if (! empty($data['project_id'])) {
            $query->where('project_id', $data['project_id']);
        }
        if (! empty($data['subject_id'])) {
            abort_unless($this->access->target($actor, $scope, $data['subject_type'], $data['subject_id']), 404);
            $query->whereHas('attachments', fn ($q) => $q->where('subject_type', $data['subject_type'])->where('subject_id', $data['subject_id']));
        }
        $page = $query->select(['id', 'scope_id', 'project_id', 'created_by', 'title', 'description', 'visibility', 'revision', 'created_at', 'updated_at'])
            ->latest('updated_at')->orderByDesc('id')->simplePaginate(30);
        $abilities = ['update' => $this->contractors->allows($actor, $scope, 'task.update'), 'delete' => $this->contractors->allows($actor, $scope, 'task.delete')];

        return response()->json(['data' => $page->getCollection()->map(fn (Diagram $diagram): array => $this->present($request, $scope, $diagram, false, $abilities)),
            'meta' => ['page' => $page->currentPage(), 'has_more' => $page->hasMorePages()]]);
    }

    public function options(Request $request, Scope $scope): JsonResponse
    {
        $actor = $this->context->actor($request);

        return response()->json(['data' => ['projects' => $this->contractors->constrainProjects($scope->projects()->getQuery(), $actor, $scope)
            ->orderBy('sort_order')->get(['id', 'title', 'key']), 'can_create' => $this->contractors->allows($actor, $scope, 'task.create')]]);
    }

    public function show(Request $request, Scope $scope, Diagram $diagram): JsonResponse
    {
        abort_unless($this->access->can($this->context->actor($request), $scope, $diagram), 404);

        return response()->json(['data' => $this->present($request, $scope, $diagram, true)]);
    }

    public function store(DiagramRequest $request, Scope $scope): JsonResponse
    {
        $data = $request->validated();
        $actor = $this->context->actor($request);
        abort_unless($this->access->projectAllowed($actor, $scope, $data['project_id'] ?? null), 403);
        $diagram = DB::transaction(function () use ($data, $request, $scope, $actor): Diagram {
            $diagram = Diagram::create([...$data, 'created_by' => $actor->id, 'scope_id' => $scope->id, 'revision' => 1]);
            $this->snapshot($diagram, $actor->id);
            $this->audit($request, $scope, $diagram, 'created');

            return $diagram;
        });

        return response()->json(['data' => $this->present($request, $scope, $diagram, true)], 201);
    }

    public function update(DiagramRequest $request, Scope $scope, Diagram $diagram): JsonResponse
    {
        $data = $request->validated();
        $diagram = DB::transaction(function () use ($request, $scope, $diagram, $data): Diagram {
            $current = Diagram::query()->lockForUpdate()->findOrFail($diagram->id);
            $this->assertManage($request, $scope, $current);
            abort_unless($current->revision === $data['revision'], 409, 'Схема уже изменена в другой вкладке. Сохраните свой XML и откройте актуальную версию.');
            if (array_key_exists('project_id', $data)) {
                abort_unless($this->access->projectAllowed($this->context->actor($request), $scope, $data['project_id']), 403);
            }
            unset($data['revision']);
            if (array_key_exists('xml', $data) && ! array_key_exists('preview_svg', $data)) {
                $data['preview_svg'] = null;
            }
            $current->fill($data);
            if ($current->isDirty()) {
                $before = ['revision' => $current->revision, 'title' => $current->getOriginal('title')];
                $current->revision++;
                $current->save();
                $this->snapshot($current, $this->context->actor($request)->id);
                $this->audit($request, $scope, $current, 'updated', $before);
            }

            return $current;
        });

        return response()->json(['data' => $this->present($request, $scope, $diagram, true)]);
    }

    public function duplicate(Request $request, Scope $scope, Diagram $diagram): JsonResponse
    {
        $data = $request->validate(['title' => ['sometimes', 'required', 'string', 'max:255']]);
        $actor = $this->context->actor($request);
        abort_unless($this->access->can($actor, $scope, $diagram), 404);
        abort_unless($this->access->projectAllowed($actor, $scope, $diagram->project_id), 403);
        $copy = DB::transaction(function () use ($request, $scope, $diagram, $data, $actor): Diagram {
            $copy = Diagram::create(['scope_id' => $scope->id, 'created_by' => $actor->id, 'project_id' => $diagram->project_id,
                'title' => $data['title'] ?? mb_substr($diagram->title, 0, 245).' — копия', 'description' => $diagram->description,
                'visibility' => 'private', 'xml' => $diagram->xml, 'preview_svg' => $diagram->preview_svg]);
            $this->snapshot($copy, $actor->id);
            $this->audit($request, $scope, $copy, 'duplicated', ['source_id' => $diagram->id]);

            return $copy;
        });

        return response()->json(['data' => $this->present($request, $scope, $copy, true)], 201);
    }

    public function destroy(Request $request, Scope $scope, Diagram $diagram): Response
    {
        abort_unless($this->access->can($this->context->actor($request), $scope, $diagram, 'task.delete'), 404);
        DB::transaction(function () use ($request, $scope, $diagram): void {
            $this->audit($request, $scope, $diagram, 'deleted');
            $diagram->delete();
        });

        return response()->noContent();
    }

    public function revisions(Request $request, Scope $scope, Diagram $diagram): JsonResponse
    {
        abort_unless($this->access->can($this->context->actor($request), $scope, $diagram), 404);

        return response()->json(['data' => $diagram->revisions()->with('creator:id,name')->get(['id', 'diagram_id', 'created_by', 'number', 'title', 'created_at'])]);
    }

    public function revision(Request $request, Scope $scope, Diagram $diagram, DiagramRevision $revision): JsonResponse
    {
        abort_unless($revision->diagram_id === $diagram->id && $this->access->can($this->context->actor($request), $scope, $diagram), 404);

        return response()->json(['data' => $revision->only(['id', 'number', 'title', 'xml', 'preview_svg', 'created_at'])]);
    }

    public function candidates(Request $request, Scope $scope): JsonResponse
    {
        $data = $request->validate(['type' => ['required', Rule::in(array_keys(DiagramAccessService::SUBJECTS))], 'q' => ['nullable', 'string', 'max:120']]);

        return response()->json(['data' => $this->access->candidates($this->context->actor($request), $scope, $data['type'], $data['q'] ?? '')]);
    }

    public function attach(Request $request, Scope $scope, Diagram $diagram): JsonResponse
    {
        $this->assertManage($request, $scope, $diagram);
        $data = $request->validate(['subject_type' => ['required', Rule::in(array_keys(DiagramAccessService::SUBJECTS))], 'subject_id' => ['required', 'ulid']]);
        $this->assertWritableTarget($request, $scope, $data['subject_type'], $data['subject_id']);
        $diagram->attachments()->firstOrCreate($data, ['created_by' => $this->context->actor($request)->id]);

        return $this->show($request, $scope, $diagram);
    }

    public function detach(Request $request, Scope $scope, Diagram $diagram, DiagramAttachment $attachment): Response
    {
        $this->assertManage($request, $scope, $diagram);
        abort_unless($attachment->diagram_id === $diagram->id, 404);
        $this->assertWritableTarget($request, $scope, $attachment->subject_type, $attachment->subject_id);
        $attachment->delete();

        return response()->noContent();
    }

    private function assertWritableTarget(Request $request, Scope $scope, string $type, string $id): void
    {
        $actor = $this->context->actor($request);
        $target = $this->access->target($actor, $scope, $type, $id);
        abort_unless($target, 404);
        if (array_key_exists($type, FilerAccessService::SUBJECTS)) {
            abort_unless($this->files->subject($actor, $scope, $target, true), 403);
        } elseif ($target instanceof BookPage) {
            abort_unless($this->contractors->allows($actor, $scope, 'book.update'), 403);
        } elseif ($target instanceof PlanItem) {
            $this->plans->authorizeWrite($actor, $scope, $target->project_id);
        } elseif ($target instanceof FilerFile) {
            abort_unless($this->files->file($actor, $scope, $target, true), 403);
        } else {
            abort_unless($this->targets->projectAllowed($actor, $scope, $target->project_id, 'task.update'), 403);
        }
    }

    private function assertManage(Request $request, Scope $scope, Diagram $diagram): void
    {
        abort_unless($this->access->can($this->context->actor($request), $scope, $diagram, 'task.update'), 404);
    }

    private function snapshot(Diagram $diagram, string $actorId): void
    {
        $diagram->revisions()->create(['created_by' => $actorId, 'number' => $diagram->revision, 'title' => $diagram->title, 'xml' => $diagram->xml, 'preview_svg' => $diagram->preview_svg]);
        $diagram->revisions()->where('number', '<=', $diagram->revision - 100)->delete();
    }

    private function audit(Request $request, Scope $scope, Diagram $diagram, string $action, array $before = []): void
    {
        ActivityLog::create(['scope_id' => $scope->id, 'actor_id' => $this->context->actor($request)->id, 'subject_type' => 'diagram', 'subject_id' => $diagram->id,
            'action' => 'diagram.'.$action, 'before' => $before, 'after' => ['revision' => $diagram->revision, 'title' => $diagram->title], 'context' => $this->context->auditMetadata($request)]);
    }

    private function present(Request $request, Scope $scope, Diagram $diagram, bool $full = false, ?array $abilities = null): array
    {
        $actor = $this->context->actor($request);
        $diagram->loadMissing(['creator', 'project']);
        $data = $diagram->only(['id', 'scope_id', 'project_id', 'title', 'description', 'visibility', 'revision', 'created_at', 'updated_at']);
        $data['creator'] = $diagram->creator?->only(['id', 'name']);
        $data['project'] = $diagram->project?->only(['id', 'title', 'key']);
        $manager = $diagram->created_by === $actor->id || $scope->owner_id === $actor->id;
        $data['can_manage'] = $manager && ($abilities['update'] ?? $this->contractors->allows($actor, $scope, 'task.update'));
        $data['can_delete'] = $manager && ($abilities['delete'] ?? $this->contractors->allows($actor, $scope, 'task.delete'));
        if ($full) {
            $data['xml'] = $diagram->xml;
            $data['preview_svg'] = $diagram->preview_svg;
            $data['attachments'] = $diagram->attachments()->get()->map(function (DiagramAttachment $link) use ($actor, $scope): ?array {
                $target = $this->access->target($actor, $scope, $link->subject_type, $link->subject_id);

                return $target ? ['attachment_id' => $link->id, ...$this->access->describe($link->subject_type, $target)] : null;
            })->filter()->values();
        }

        return $data;
    }
}
