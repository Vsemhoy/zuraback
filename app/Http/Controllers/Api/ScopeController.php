<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreScopeRequest;
use App\Http\Requests\Api\UpdateScopeAppearanceRequest;
use App\Http\Requests\Api\UpdateScopeModulesRequest;
use App\Http\Resources\ScopeResource;
use App\Models\ActivityLog;
use App\Models\Scope;
use App\Services\ScopeModuleService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ScopeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $scopes = Scope::query()
            ->where(fn ($query) => $query->where('owner_id', $request->user()->id)
                ->orWhereHas('members', fn ($members) => $members->where('user_id', $request->user()->id)->where('is_active', true)))
            ->withExists(['members as is_appearance_admin' => fn ($query) => $query->where('user_id', $request->user()->id)->where('is_active', true)->where('role', 'admin')])
            ->latest()
            ->paginate();

        return ScopeResource::collection($scopes);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreScopeRequest $request): ScopeResource
    {
        $scope = DB::transaction(function () use ($request): Scope {
            $scope = Scope::query()->create([...$request->validated(), 'owner_id' => $request->user()->id]);
            $scope->members()->create(['user_id' => $request->user()->id, 'role' => 'owner', 'joined_at' => now()]);

            return $scope;
        });

        return new ScopeResource($scope);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Scope $scope): ScopeResource
    {
        $scope->loadExists(['members as is_appearance_admin' => fn ($query) => $query->where('user_id', $request->user()->id)->where('is_active', true)->where('role', 'admin')]);

        return new ScopeResource($scope);
    }

    public function updateModules(UpdateScopeModulesRequest $request, Scope $scope): ScopeResource
    {
        DB::transaction(function () use ($request, $scope): void {
            $locked = Scope::query()->whereKey($scope->id)->lockForUpdate()->firstOrFail();
            $before = ScopeModuleService::settings($locked);
            $settings = $locked->settings ?? [];
            $payload = $request->validated();
            $payload['enabled'] = array_map(fn ($value): bool => (bool) $value, $payload['enabled']);
            $locked->settings = [...$settings, 'modules' => $payload];
            $normalized = ScopeModuleService::settings($locked);
            $locked->update(['settings' => [...$settings, 'modules' => $normalized]]);
            ActivityLog::query()->create(['scope_id' => $scope->id, 'actor_id' => $request->user()->id,
                'subject_type' => 'scope', 'subject_id' => $scope->id, 'action' => 'scope.modules_updated',
                'before' => $before, 'after' => $normalized]);
        });

        return $this->show($request, $scope->refresh());
    }

    public function updateAppearance(UpdateScopeAppearanceRequest $request, Scope $scope): ScopeResource
    {
        DB::transaction(function () use ($request, $scope): void {
            $locked = Scope::query()->whereKey($scope->id)->lockForUpdate()->firstOrFail();
            $settings = $locked->settings ?? [];
            $theme = $request->validated('header_theme');
            if (! Arr::has($theme, 'blur_px') && Arr::has($settings, 'header_theme.blur_px')) {
                $theme['blur_px'] = Arr::get($settings, 'header_theme.blur_px');
            }
            $locked->update(['settings' => [...$settings, 'header_theme' => $theme]]);
        });

        return $this->show($request, $scope->refresh());
    }
}
