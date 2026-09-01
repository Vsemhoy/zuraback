<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreAgentTokenRequest;
use App\Http\Requests\Api\StoreContractorRequest;
use App\Http\Requests\Api\UpdateContractorAccessRequest;
use App\Http\Requests\Api\UpdateContractorRequest;
use App\Http\Resources\ContractorResource;
use App\Models\ActivityLog;
use App\Models\BookPage;
use App\Models\ContractorDelegation;
use App\Models\ProjectMember;
use App\Models\Scope;
use App\Models\Task;
use App\Models\User;
use App\Services\ContractorAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ContractorController extends Controller
{
    public function __construct(private readonly ContractorAccessService $access) {}

    public function index(Request $request, Scope $scope): AnonymousResourceCollection
    {
        $canManageAll = $this->access->allows($request->user(), $scope, 'contractor.manage');
        abort_unless($canManageAll || $this->access->allows($request->user(), $scope, 'agent.manage_own'), Response::HTTP_FORBIDDEN, 'The agent.manage_own capability is required.');

        $contractors = User::query()
            ->where(function ($query) use ($scope): void {
                $query->whereKey($scope->owner_id)
                    ->orWhereHas('scopeMemberships', fn ($members) => $members
                        ->where('scope_id', $scope->id)
                        ->where('is_active', true));
            })
            ->when(! $canManageAll, fn ($query) => $query
                ->where('type', 'agent')
                ->where('created_by', $request->user()->id))
            ->with($this->relations($scope, $request->user()))
            ->orderByRaw("CASE type WHEN 'real' THEN 1 WHEN 'virtual' THEN 2 ELSE 3 END")
            ->orderBy('name')
            ->get()
            ->sortBy(fn (User $user): int => $user->id === $scope->owner_id
                ? -1
                : (int) ($user->scopeMemberships->firstWhere('scope_id', $scope->id)?->sort_order ?? 0))
            ->values();

        return ContractorResource::collection($contractors);
    }

    public function options(Request $request, Scope $scope): JsonResponse
    {
        $canManageAll = $this->access->allows($request->user(), $scope, 'contractor.manage');
        abort_unless($canManageAll || $this->access->allows($request->user(), $scope, 'agent.manage_own'), Response::HTTP_FORBIDDEN, 'The agent.manage_own capability is required.');

        $manageableScopes = Scope::query()
            ->where('owner_id', $request->user()->id)
            ->orWhereHas('members', fn ($query) => $query->where('user_id', $request->user()->id)->where('is_active', true))
            ->get()->filter(fn (Scope $candidate): bool => app(ContractorAccessService::class)->allows($request->user(), $candidate, 'contractor.manage'));

        return response()->json(['data' => [
            'abilities' => $canManageAll ? ContractorAccessService::ABILITIES : $this->access->delegableAbilities($request->user(), $scope),
            'types' => $canManageAll ? User::TYPES : ['agent'],
            'statuses' => User::STATUSES,
            'manageable_scopes' => $canManageAll ? $manageableScopes->map(fn (Scope $candidate): array => ['id' => $candidate->id, 'name' => $candidate->name])->values() : [],
            'can_manage_all' => $canManageAll,
        ]]);
    }

    public function assignable(Request $request, Scope $scope): JsonResponse
    {
        $users = User::query()
            ->where('status', 'active')
            ->where('is_active', true)
            ->where(function ($query) use ($scope): void {
                $query->whereKey($scope->owner_id)
                    ->orWhereHas('scopeMemberships', fn ($members) => $members
                        ->where('scope_id', $scope->id)
                        ->where('is_active', true));
            })
            ->with([
                'scopeMemberships' => fn ($query) => $query->where('scope_id', $scope->id),
                'projectMemberships' => fn ($query) => $query->whereHas('project', fn ($projects) => $projects->where('scope_id', $scope->id)),
            ])
            ->orderBy('name')
            ->get()
            ->map(function (User $user) use ($request, $scope): array {
                $membership = $user->scopeMemberships->first();

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'position' => $user->position,
                    'type' => $user->type,
                    'is_current' => $user->id === $request->user()->id,
                    'project_access_mode' => $scope->owner_id === $user->id ? 'all' : ($membership?->project_access_mode ?? 'none'),
                    'project_ids' => $user->projectMemberships->pluck('project_id')->values(),
                ];
            });

        return response()->json(['data' => [
            'assignees' => $users->whereIn('type', ['real', 'virtual'])->values(),
            'agents' => $users->where('type', 'agent')->values(),
        ]]);
    }

    public function store(StoreContractorRequest $request, Scope $scope): ContractorResource
    {
        $data = $request->validated();
        $canManageAll = $this->access->allows($request->user(), $scope, 'contractor.manage');
        abort_unless($canManageAll || ($this->access->allows($request->user(), $scope, 'agent.manage_own') && $data['type'] === 'agent'), Response::HTTP_FORBIDDEN, 'Only own agent accounts can be created without contractor.manage.');
        if (! $canManageAll) {
            $data = $this->constrainOwnAgentData($request->user(), $scope, $data);
        }

        $contractor = DB::transaction(function () use ($data, $request, $scope): User {
            $status = $data['status'] ?? 'active';
            $type = $data['type'];
            $contractor = User::query()->create([
                ...Arr::only($data, ['name', 'position', 'type', 'username', 'email']),
                'status' => $status,
                'password' => $type === 'real' ? $data['password'] : null,
                'created_by' => $request->user()->id,
                'activated_at' => $type === 'real' && $status === 'active' ? now() : null,
                'is_active' => $status === 'active',
            ]);

            $scope->members()->create([
                'user_id' => $contractor->id,
                'role' => $data['role'] ?? 'member',
                'permissions' => $data['permissions'] ?? ['allow' => [], 'deny' => []],
                'project_access_mode' => $data['project_access_mode'] ?? 'all',
                'book_access_mode' => $data['book_access_mode'] ?? 'none',
                'sort_order' => $data['sort_order'] ?? (((int) $scope->members()->max('sort_order')) + 1000),
                'joined_at' => now(),
            ]);

            $this->syncProjects($contractor, $data['project_ids'] ?? [], $request->user()->id);
            $this->syncDelegation($scope, $request->user(), $contractor, $data['can_act_as'] ?? $contractor->isVirtual());

            return $contractor;
        });

        return new ContractorResource($contractor->load($this->relations($scope, $request->user())));
    }

    public function show(Request $request, Scope $scope, User $contractor): ContractorResource
    {
        $this->assertContractor($scope, $contractor);
        $this->assertCanManage($request, $scope, $contractor);

        return new ContractorResource($contractor->load($this->relations($scope, $request->user())));
    }

    public function update(UpdateContractorRequest $request, Scope $scope, User $contractor): ContractorResource
    {
        $this->assertContractor($scope, $contractor);
        $this->assertCanManage($request, $scope, $contractor);
        $data = $request->validated();
        if (! $this->access->allows($request->user(), $scope, 'contractor.manage')) {
            abort_if(isset($data['type']) && $data['type'] !== 'agent', 403, 'Own agents cannot be converted to another account type.');
            $data['type'] = 'agent';
        }
        $targetType = $data['type'] ?? $contractor->type;
        $targetEmail = array_key_exists('email', $data) ? $data['email'] : $contractor->email;
        $targetPassword = array_key_exists('password', $data) ? $data['password'] : $contractor->password;

        abort_if($targetType === 'real' && ($targetEmail === null || $targetPassword === null), 422, 'A real user requires an email and password.');

        if ($targetType !== 'real') {
            $data['password'] = null;
        }

        if (isset($data['status'])) {
            $data['is_active'] = $data['status'] === 'active';
        }

        if ($targetType === 'real' && ! $contractor->isReal()) {
            $data['activated_at'] = now();
        }

        $contractor->update($data);

        if (! $contractor->isAgent()) {
            $contractor->tokens()->delete();
        }

        return new ContractorResource($contractor->fresh()->load($this->relations($scope, $request->user())));
    }

    public function updateAccess(UpdateContractorAccessRequest $request, Scope $scope, User $contractor): ContractorResource
    {
        $this->assertContractor($scope, $contractor);
        $this->assertCanManage($request, $scope, $contractor);
        $data = $request->validated();
        if (! $this->access->allows($request->user(), $scope, 'contractor.manage')) {
            $data = $this->constrainOwnAgentData($request->user(), $scope, $data);
        }
        $isOwner = $scope->owner_id === $contractor->id;
        abort_if($isOwner && $data['role'] !== 'owner', 422, 'The scope owner role cannot be changed.');
        abort_if(! $isOwner && $data['role'] === 'owner', 422, 'The owner role is reserved for the scope owner.');

        DB::transaction(function () use ($contractor, $data, $request, $scope): void {
            $scope->members()->updateOrCreate(
                ['user_id' => $contractor->id],
                [
                    'role' => $data['role'],
                    'permissions' => $data['permissions'],
                    'project_access_mode' => $data['project_access_mode'],
                    'book_access_mode' => $data['book_access_mode'] ?? $scope->members()->where('user_id', $contractor->id)->value('book_access_mode') ?? 'none',
                    'sort_order' => $data['sort_order'] ?? $scope->members()->where('user_id', $contractor->id)->value('sort_order') ?? 0,
                    'is_active' => true,
                    'joined_at' => now(),
                ],
            );
            $this->syncProjects($contractor, $data['project_ids'], $request->user()->id);
            $this->syncDelegation($scope, $request->user(), $contractor, $data['can_act_as'] ?? false);
        });

        return new ContractorResource($contractor->fresh()->load($this->relations($scope, $request->user())));
    }

    public function addScopes(Request $request, Scope $scope, User $contractor): ContractorResource
    {
        $this->assertContractor($scope, $contractor);
        $data = $request->validate(['scope_ids' => ['required', 'array', 'min:1'], 'scope_ids.*' => ['ulid', 'distinct', 'exists:scopes,id']]);
        $targets = Scope::query()->whereIn('id', $data['scope_ids'])->get();
        abort_unless($targets->count() === count($data['scope_ids']), 422);
        foreach ($targets as $target) {
            abort_unless(app(ContractorAccessService::class)->allows($request->user(), $target, 'contractor.manage'), 403, 'One or more scopes cannot be managed by this user.');
        }

        DB::transaction(function () use ($contractor, $targets): void {
            foreach ($targets as $target) {
                if ($target->owner_id === $contractor->id) {
                    continue;
                }
                $membership = $target->members()->where('user_id', $contractor->id)->first();
                if ($membership) {
                    $membership->update(['is_active' => true, 'joined_at' => $membership->joined_at ?? now()]);
                } else {
                    $target->members()->create([
                        'user_id' => $contractor->id,
                        'role' => 'member',
                        'permissions' => ['allow' => [], 'deny' => []],
                        'project_access_mode' => 'none',
                        'book_access_mode' => 'none',
                        'is_active' => true,
                        'joined_at' => now(),
                    ]);
                }
            }
        });

        return new ContractorResource($contractor->fresh()->load($this->relations($scope, $request->user())));
    }

    public function storeToken(StoreAgentTokenRequest $request, Scope $scope, User $contractor): JsonResponse
    {
        $this->assertContractor($scope, $contractor);
        $this->assertCanManage($request, $scope, $contractor);
        abort_unless($contractor->isAgent(), 422, 'Tokens can only be issued to agent accounts.');

        $data = $request->validated();
        $token = $contractor->createToken(
            $data['name'],
            $data['abilities'],
            isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
        );

        return response()->json(['data' => [
            'id' => $token->accessToken->id,
            'name' => $token->accessToken->name,
            'abilities' => $token->accessToken->abilities,
            'expires_at' => $token->accessToken->expires_at,
            'token' => $token->plainTextToken,
        ]], Response::HTTP_CREATED);
    }

    public function destroyToken(Request $request, Scope $scope, User $contractor, int $token): Response
    {
        $this->assertContractor($scope, $contractor);
        $this->assertCanManage($request, $scope, $contractor);
        $contractor->tokens()->whereKey($token)->delete();

        return response()->noContent();
    }

    public function destroy(Request $request, Scope $scope, User $contractor): Response
    {
        $this->assertContractor($scope, $contractor);
        $canDeleteOwnAgent = $this->access->allows($request->user(), $scope, 'agent.manage_own')
            && $this->access->canManageContractor($request->user(), $scope, $contractor);
        abort_unless($this->access->allows($request->user(), $scope, 'contractor.delete') || $canDeleteOwnAgent, Response::HTTP_FORBIDDEN, 'The contractor.delete capability is required.');
        abort_if($contractor->is($request->user()), 422, 'You cannot delete the account currently in use.');
        abort_if($contractor->ownedScopes()->exists(), 422, 'A scope owner cannot be deleted. Transfer or delete their scopes first.');

        DB::transaction(function () use ($request, $scope, $contractor): void {
            $contractor->tokens()->delete();
            $contractor->scopeMemberships()->update(['is_active' => false]);
            $contractor->projectMemberships()->update(['is_active' => false]);
            $contractor->delegatedPersonas()->update(['is_active' => false]);
            $contractor->receivedDelegations()->update(['is_active' => false]);
            Task::query()->where('assignee_id', $contractor->id)->update(['assignee_id' => null]);
            Task::query()->where('customer_id', $contractor->id)->update(['customer_id' => null]);
            Task::query()->where('delegated_agent_id', $contractor->id)->update(['delegated_agent_id' => null, 'is_agent_delegatable' => false]);
            Task::query()->where('approved_by', $contractor->id)->update(['approved_by' => null, 'approved_at' => null]);
            BookPage::query()->where('editing_by', $contractor->id)->update(['editing_by' => null, 'editing_started_at' => null]);
            $contractor->update(['status' => 'blocked', 'is_active' => false]);
            $contractor->delete();

            ActivityLog::query()->create([
                'scope_id' => $scope->id,
                'actor_id' => $request->user()->id,
                'subject_type' => 'user',
                'subject_id' => $contractor->id,
                'action' => 'contractor.deleted',
                'before' => ['name' => $contractor->name, 'type' => $contractor->type],
                'context' => ['soft_deleted' => true],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        });

        return response()->noContent();
    }

    public function act(Request $request, Scope $scope, User $contractor): JsonResponse
    {
        $this->assertContractor($scope, $contractor);
        abort_unless($contractor->isVirtual() && $contractor->is_active && $contractor->status === 'active', 422, 'Only an active virtual user can become a persona.');

        $delegated = ContractorDelegation::query()
            ->where('scope_id', $scope->id)
            ->where('operator_id', $request->user()->id)
            ->where('contractor_id', $contractor->id)
            ->where('is_active', true)
            ->exists();
        abort_unless($delegated, Response::HTTP_FORBIDDEN, 'This virtual user has not been delegated to you.');

        $request->session()->put([
            'contractor.actor_id' => $contractor->id,
            'contractor.scope_id' => $scope->id,
        ]);

        return response()->json(['data' => ['acting_as' => $this->summary($contractor), 'scope_id' => $scope->id]]);
    }

    public function stopActing(Request $request): Response
    {
        $request->session()->forget(['contractor.actor_id', 'contractor.scope_id']);

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function relations(Scope $scope, User $operator): array
    {
        return [
            'scopeMemberships' => fn ($query) => $query->where('is_active', true)->with('scope:id,name'),
            'ownedScopes:id,owner_id,name',
            'projectMemberships' => fn ($query) => $query->whereHas('project', fn ($projects) => $projects->where('scope_id', $scope->id))->with('project:id,title,key,color'),
            'tokens' => fn ($query) => $query->latest(),
            'receivedDelegations' => fn ($query) => $query->where('scope_id', $scope->id)->where('operator_id', $operator->id),
        ];
    }

    private function assertContractor(Scope $scope, User $contractor): void
    {
        $belongsToScope = $scope->owner_id === $contractor->id || $scope->members()
            ->where('user_id', $contractor->id)
            ->exists();
        abort_unless($belongsToScope, Response::HTTP_NOT_FOUND);
    }

    private function assertCanManage(Request $request, Scope $scope, User $contractor): void
    {
        abort_unless($this->access->canManageContractor($request->user(), $scope, $contractor), Response::HTTP_FORBIDDEN, 'This contractor cannot be managed by this user.');
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function constrainOwnAgentData(User $manager, Scope $scope, array $data): array
    {
        $membership = $this->access->membership($manager, $scope);
        $allowedAbilities = $this->access->delegableAbilities($manager, $scope);
        $requestedAllow = $data['permissions']['allow'] ?? [];
        abort_if(array_diff($requestedAllow, $allowedAbilities) !== [], 403, 'An own agent cannot receive capabilities its manager does not have.');

        $data['type'] = 'agent';
        $data['role'] = 'observer';
        $data['can_act_as'] = false;
        $data['book_access_mode'] = 'none';
        $data['permissions'] = [
            'allow' => array_values(array_intersect($requestedAllow, $allowedAbilities)),
            'deny' => array_values($data['permissions']['deny'] ?? []),
        ];

        if ($membership?->project_access_mode === 'all') {
            return $data;
        }

        $allowedProjectIds = $manager->projectMemberships()
            ->whereHas('project', fn ($query) => $query->where('scope_id', $scope->id))
            ->where('is_active', true)
            ->pluck('project_id')
            ->all();
        $data['project_ids'] = array_values(array_intersect($data['project_ids'] ?? [], $allowedProjectIds));
        $data['project_access_mode'] = $membership?->project_access_mode === 'restricted' ? 'restricted' : 'none';

        return $data;
    }

    /** @param array<int, string> $projectIds */
    private function syncProjects(User $contractor, array $projectIds, string $assignedBy): void
    {
        $contractor->projectMemberships()->delete();

        foreach ($projectIds as $projectId) {
            ProjectMember::query()->create([
                'project_id' => $projectId,
                'user_id' => $contractor->id,
                'assigned_by' => $assignedBy,
                'permissions' => ['allow' => [], 'deny' => []],
            ]);
        }
    }

    private function syncDelegation(Scope $scope, User $operator, User $contractor, bool $canActAs): void
    {
        if (! $contractor->isVirtual()) {
            ContractorDelegation::query()->where('scope_id', $scope->id)->where('contractor_id', $contractor->id)->delete();

            return;
        }

        ContractorDelegation::query()->updateOrCreate(
            ['scope_id' => $scope->id, 'operator_id' => $operator->id, 'contractor_id' => $contractor->id],
            ['assigned_by' => $operator->id, 'is_active' => $canActAs],
        );
    }

    /** @return array{id: string, name: string, type: string} */
    private function summary(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'type' => $user->type];
    }
}
