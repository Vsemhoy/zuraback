<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ContentNavigationLinkRequest;
use App\Models\ActivityLog;
use App\Models\ContentNavigationLink;
use App\Models\Diagram;
use App\Models\Scope;
use App\Services\ContentNavigationService;
use App\Services\ContractorContext;
use App\Services\DiagramAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ContentNavigationController extends Controller
{
    public function __construct(private readonly ContentNavigationService $navigation, private readonly ContractorContext $context, private readonly DiagramAccessService $diagrams) {}

    public function show(Request $request, Scope $scope, string $type, string $id): JsonResponse
    {
        $actor = $this->context->actor($request);
        $record = $this->navigation->resolve($actor, $scope, $type, $id);

        return response()->json(['data' => [...$this->navigation->describe($type, $record), 'document' => $this->navigation->document($type, $record),
            'can_manage' => $this->navigation->can($actor, $scope, $record, true),
            'can_edit' => $record instanceof Diagram && $this->diagrams->canEdit($actor, $scope, $record),
            'links' => $this->navigation->links($actor, $scope, $type, $id)]]);
    }

    public function candidates(Request $request, Scope $scope): JsonResponse
    {
        $data = $request->validate(['type' => ['required', Rule::in(array_keys(ContentNavigationService::TYPES))], 'q' => ['nullable', 'string', 'max:120'], 'page' => ['sometimes', 'integer', 'between:1,1000']]);

        return response()->json($this->navigation->candidates($this->context->actor($request), $scope, $data['type'], $data['q'] ?? '', $data['page'] ?? 1));
    }

    public function store(ContentNavigationLinkRequest $request, Scope $scope, string $type, string $id): JsonResponse
    {
        return $this->saveLink($request, $scope, $type, $id);
    }

    public function update(ContentNavigationLinkRequest $request, Scope $scope, string $type, string $id, ContentNavigationLink $link): JsonResponse
    {
        abort_unless($link->scope_id === $scope->id && $link->source_type === $type && $link->source_id === $id, 404);

        return $this->saveLink($request, $scope, $type, $id, $link);
    }

    private function saveLink(ContentNavigationLinkRequest $request, Scope $scope, string $type, string $id, ?ContentNavigationLink $link = null): JsonResponse
    {
        $data = $request->validated();
        DB::transaction(function () use ($request, $scope, $type, $id, $data, $link): void {
            $actor = $this->context->actor($request);
            $source = $this->navigation->resolve($actor, $scope, $type, $id, true);
            $source = $source->newQuery()->lockForUpdate()->findOrFail($id);
            abort_unless($this->navigation->can($actor, $scope, $source, true), 404);
            $this->navigation->resolve($actor, $scope, $data['target_type'], $data['target_id']);
            if (! empty($data['region'])) {
                abort_unless($source instanceof Diagram && $source->revision === (int) $data['source_revision'], 409, 'Схема изменилась. Обновите просмотрщик и проверьте положение рамки.');
                $xml = new \DOMDocument;
                $xml->loadXML($source->xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
                $ids = $xml->documentElement?->tagName === 'mxGraphModel' ? ['single-page'] : [];
                foreach ($xml->getElementsByTagName('diagram') as $index => $page) {
                    $ids[] = $page->getAttribute('id') ?: 'page-'.$index;
                }
                abort_unless(in_array($data['region']['page_id'], $ids, true), 422, 'Страница схемы не найдена.');
            }
            $attributes = [...$data, 'region' => $data['region'] ?? null, 'source_revision' => empty($data['region']) ? null : $data['source_revision']];
            if ($link) {
                $before = $link->only(['label', 'target_type', 'target_id', 'region', 'source_revision']);
                $link->update($attributes);
            } else {
                abort_if(ContentNavigationLink::where('scope_id', $scope->id)->where('source_type', $type)->where('source_id', $id)->count() >= 500, 422, 'На документе уже 500 навигационных ссылок.');
                $before = [];
                $link = ContentNavigationLink::create([...$attributes, 'scope_id' => $scope->id, 'source_type' => $type, 'source_id' => $id, 'created_by' => $actor->id]);
            }
            ActivityLog::create(['scope_id' => $scope->id, 'actor_id' => $actor->id, 'subject_type' => $type, 'subject_id' => $id,
                'action' => $before ? 'content.navigation.updated' : 'content.navigation.created', 'before' => $before,
                'after' => ['link_id' => $link->id, ...$link->only(['target_type', 'target_id', 'label', 'region', 'source_revision'])], 'context' => $this->context->auditMetadata($request)]);
        });

        return $this->show($request, $scope, $type, $id)->setStatusCode($link ? 200 : 201);
    }

    public function destroy(Request $request, Scope $scope, string $type, string $id, ContentNavigationLink $link): Response
    {
        $this->navigation->resolve($this->context->actor($request), $scope, $type, $id, true);
        abort_unless($link->scope_id === $scope->id && $link->source_type === $type && $link->source_id === $id, 404);
        DB::transaction(function () use ($request, $scope, $type, $id, $link): void {
            ActivityLog::create(['scope_id' => $scope->id, 'actor_id' => $this->context->actor($request)->id, 'subject_type' => $type, 'subject_id' => $id,
                'action' => 'content.navigation.deleted', 'before' => ['link_id' => $link->id, ...$link->only(['target_type', 'target_id', 'label', 'region'])], 'context' => $this->context->auditMetadata($request)]);
            $link->delete();
        });

        return response()->noContent();
    }
}
