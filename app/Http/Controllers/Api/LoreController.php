<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoreArea;
use App\Models\LoreEntry;
use App\Models\LoreRevision;
use App\Models\LoreTag;
use App\Models\Scope;
use App\Services\ContractorAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LoreController extends Controller
{
    public function __construct(private readonly ContractorAccessService $access) {}

    public function index(Request $request, Scope $scope): JsonResponse
    {
        $asOf = Carbon::parse($request->query('as_of', now()));
        $query = $this->visibleQuery($request, $scope)->with(['project:id,title,key', 'area:id,name,parent_id', 'tags:id,name,slug,color', 'creator:id,name', 'revisions.creator:id,name', 'starredBy:id']);
        foreach (['project_id', 'area_id', 'type', 'importance', 'criticality'] as $field) if ($request->filled($field)) $query->where($field, $request->query($field));
        if ($request->boolean('starred')) $query->whereHas('starredBy', fn (Builder $q) => $q->where('users.id', $request->user()->id));
        if ($request->filled('tag')) $query->whereHas('tags', fn (Builder $q) => $q->where('slug', $request->query('tag')));
        if ($request->filled('q')) {
            $term = '%'.$request->query('q').'%';
            $query->where(fn (Builder $q) => $q->where('code', 'like', $term)->orWhereHas('revisions', fn (Builder $r) => $r->where('title', 'like', $term)->orWhere('content', 'like', $term)));
        }
        $items = $query->latest()->get()->map(fn (LoreEntry $entry) => $this->present($entry, $request->user()->id, $asOf));
        return response()->json(['data' => $items->values()]);
    }

    public function context(Request $request, Scope $scope): JsonResponse
    {
        $asOf = Carbon::parse($request->query('as_of', now()));
        $entries = $this->visibleQuery($request, $scope)
            ->when($request->filled('project_id'), fn (Builder $q) => $q->where('project_id', $request->query('project_id')))
            ->with(['project:id,title,key', 'area:id,name,parent_id', 'tags:id,name,slug', 'revisions', 'starredBy:id'])->get()
            ->map(fn (LoreEntry $entry) => $this->present($entry, $request->user()->id, $asOf))
            ->filter(fn (array $entry) => $entry['current_revision'] !== null)
            ->sortBy(fn (array $entry) => sprintf('%d%d%s', $entry['is_starred'] ? 0 : 1, $this->importanceRank($entry['importance']), $entry['code']))->values();
        return response()->json(['data' => ['as_of' => $asOf->toIso8601String(), 'starred' => $entries->where('is_starred', true)->values(), 'foundations' => $entries->where('importance', 'foundational')->values(), 'entries' => $entries]]);
    }

    public function show(Request $request, Scope $scope, LoreEntry $loreEntry): JsonResponse
    {
        $this->assertVisible($request, $scope, $loreEntry);
        $loreEntry->load(['project:id,title,key', 'area:id,name,parent_id', 'tags:id,name,slug,color', 'creator:id,name', 'revisions.creator:id,name', 'starredBy:id']);
        return response()->json(['data' => $this->present($loreEntry, $request->user()->id, Carbon::parse($request->query('as_of', now())), true)]);
    }

    public function store(Request $request, Scope $scope): JsonResponse
    {
        $data = $request->validate($this->entryRules(true) + $this->revisionRules(true));
        $this->assertProject($request, $scope, $data['project_id'] ?? null, 'task.update');
        $this->assertArea($scope, $data['area_id'] ?? null, $data['project_id'] ?? null);
        $entry = DB::transaction(function () use ($request, $scope, $data): LoreEntry {
            $entry = LoreEntry::create(['scope_id' => $scope->id, 'created_by' => $request->user()->id, ...collect($data)->only(['project_id','area_id','code','type','importance','criticality','visibility'])->all(), 'code' => Str::upper($data['code'])]);
            $this->syncTags($entry, $scope, $data['tags'] ?? []);
            $entry->revisions()->create(['created_by' => $request->user()->id, 'version' => 1, ...collect($data)->only(['title','content','reason','status','effective_from','effective_until'])->all(), 'effective_from' => $data['effective_from'] ?? now()]);
            return $entry;
        });
        return $this->show($request, $scope, $entry);
    }

    public function update(Request $request, Scope $scope, LoreEntry $loreEntry): JsonResponse
    {
        $this->assertVisible($request, $scope, $loreEntry, 'task.update');
        $data = $request->validate($this->entryRules(false));
        if (array_key_exists('project_id', $data)) $this->assertProject($request, $scope, $data['project_id'], 'task.update');
        if (array_key_exists('area_id', $data)) $this->assertArea($scope, $data['area_id'], $data['project_id'] ?? $loreEntry->project_id);
        $loreEntry->update(collect($data)->except('tags')->all());
        if (array_key_exists('tags', $data)) $this->syncTags($loreEntry, $scope, $data['tags']);
        return $this->show($request, $scope, $loreEntry->fresh());
    }

    public function revise(Request $request, Scope $scope, LoreEntry $loreEntry): JsonResponse
    {
        $this->assertVisible($request, $scope, $loreEntry, 'task.update');
        $data = $request->validate($this->revisionRules(true));
        DB::transaction(function () use ($request, $loreEntry, $data): void {
            $from = Carbon::parse($data['effective_from'] ?? now());
            if (($data['status'] ?? 'active') === 'active') $loreEntry->revisions()->where('status', 'active')->whereNull('effective_until')->update(['effective_until' => $from]);
            $loreEntry->revisions()->create(['created_by' => $request->user()->id, 'version' => ((int) $loreEntry->revisions()->max('version')) + 1, ...$data, 'effective_from' => $from]);
        });
        return $this->show($request, $scope, $loreEntry->fresh());
    }

    public function star(Request $request, Scope $scope, LoreEntry $loreEntry): JsonResponse
    {
        $this->assertVisible($request, $scope, $loreEntry);
        $starred = $request->validate(['starred' => ['required','boolean']])['starred'];
        $starred ? $loreEntry->starredBy()->syncWithoutDetaching([$request->user()->id]) : $loreEntry->starredBy()->detach($request->user()->id);
        return response()->json(['data' => ['id' => $loreEntry->id, 'is_starred' => $starred]]);
    }

    public function areas(Request $request, Scope $scope): JsonResponse
    {
        $projectIds = $this->access->constrainProjects($scope->projects()->getQuery(), $request->user(), $scope)->pluck('id');
        $areas = LoreArea::where('scope_id', $scope->id)->where(fn (Builder $q) => $q->whereIn('project_id', $projectIds)->when($this->access->canAccessUnprojected($request->user(), $scope), fn (Builder $x) => $x->orWhereNull('project_id')))->orderBy('sort_order')->orderBy('name')->get();
        return response()->json(['data' => $areas]);
    }
    public function storeArea(Request $request, Scope $scope): JsonResponse
    {
        $data = $request->validate(['name'=>['required','string','max:120'],'project_id'=>['nullable','ulid'],'parent_id'=>['nullable','ulid'],'sort_order'=>['sometimes','integer']]);
        $this->assertProject($request, $scope, $data['project_id'] ?? null, 'task.update');
        if (!empty($data['parent_id'])) abort_unless(LoreArea::where('scope_id', $scope->id)->whereKey($data['parent_id'])->exists(), 422, 'Invalid parent area.');
        $area = LoreArea::create([...$data, 'scope_id'=>$scope->id, 'created_by'=>$request->user()->id, 'slug'=>Str::slug($data['name'])]);
        return response()->json(['data'=>$area], 201);
    }
    public function tags(Request $request, Scope $scope): JsonResponse { return response()->json(['data' => LoreTag::where('scope_id', $scope->id)->orderBy('name')->get()]); }

    private function visibleQuery(Request $request, Scope $scope): Builder
    {
        $actor = $request->user();
        $projectIds = $this->access->constrainProjects($scope->projects()->getQuery(), $actor, $scope)->pluck('id');
        return LoreEntry::where('scope_id', $scope->id)
            ->where(fn (Builder $q) => $q->whereIn('project_id', $projectIds)->when($this->access->canAccessUnprojected($actor, $scope), fn (Builder $x) => $x->orWhereNull('project_id')))
            ->where(fn (Builder $q) => $q->where('visibility', '!=', 'private')->orWhere('created_by', $actor->id));
    }

    private function assertVisible(Request $request, Scope $scope, LoreEntry $entry, string $ability = 'task.view'): void
    {
        abort_unless($entry->scope_id === $scope->id && $this->visibleQuery($request, $scope)->whereKey($entry->id)->exists() && $this->access->allows($request->user(), $scope, $ability, $entry->project), 404);
    }
    private function assertProject(Request $request, Scope $scope, ?string $projectId, string $ability): void
    {
        if (!$projectId) { abort_unless($this->access->canAccessUnprojected($request->user(), $scope), 403); return; }
        $project = $scope->projects()->findOrFail($projectId); abort_unless($this->access->canAccessProject($request->user(), $scope, $project, $ability), 403);
    }
    private function assertArea(Scope $scope, ?string $areaId, ?string $projectId): void
    {
        if (!$areaId) return;
        abort_unless(LoreArea::where('scope_id', $scope->id)->whereKey($areaId)->where(fn (Builder $q) => $q->whereNull('project_id')->orWhere('project_id', $projectId))->exists(), 422, 'Invalid area for this scope or project.');
    }
    private function present(LoreEntry $entry, string $userId, Carbon $asOf, bool $history = false): array
    {
        $revisions = $entry->revisions->sortByDesc('version');
        $current = $revisions->first(fn (LoreRevision $r) => in_array($r->status, ['active','scheduled'], true) && $r->effective_from <= $asOf && ($r->effective_until === null || $r->effective_until > $asOf));
        return [...$entry->only(['id','scope_id','project_id','area_id','code','type','importance','criticality','visibility','created_at','updated_at']), 'project'=>$entry->project, 'area'=>$entry->area, 'tags'=>$entry->tags, 'creator'=>$entry->creator, 'is_starred'=>$entry->starredBy->contains('id',$userId), 'current_revision'=>$current, 'versions_count'=>$revisions->count(), 'revisions'=>$history ? $revisions->values() : null];
    }
    private function syncTags(LoreEntry $entry, Scope $scope, array $names): void
    {
        $ids = collect($names)->filter()->unique()->map(fn (string $name) => LoreTag::firstOrCreate(['scope_id'=>$scope->id,'slug'=>Str::slug($name)], ['name'=>$name])->id);
        $entry->tags()->sync($ids);
    }
    private function importanceRank(string $importance): int { return ['foundational'=>0,'architectural'=>1,'mechanic'=>2,'detail'=>3][$importance] ?? 9; }
    private function entryRules(bool $required): array
    {
        $p=$required?'required':'sometimes'; return ['code'=>[$p,'string','max:80'],'project_id'=>['sometimes','nullable','ulid'],'area_id'=>['sometimes','nullable','ulid'],'type'=>[$p,'in:decision,mechanic,convention,constraint,hypothesis,question,context,incident,handoff'],'importance'=>[$p,'in:foundational,architectural,mechanic,detail'],'criticality'=>[$p,'in:informational,warning,compatibility,critical'],'visibility'=>[$p,'in:private,scope,public'],'tags'=>['sometimes','array'],'tags.*'=>['string','max:80']];
    }
    private function revisionRules(bool $required): array
    {
        $p=$required?'required':'sometimes'; return ['title'=>[$p,'string','max:200'],'content'=>[$p,'string'],'reason'=>['sometimes','nullable','string'],'status'=>[$p,'in:draft,scheduled,active,cancelled'],'effective_from'=>['sometimes','date'],'effective_until'=>['sometimes','nullable','date','after:effective_from']];
    }
}
