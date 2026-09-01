<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AgentTaskController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->tokenCan('task.view'), 403, 'The task.view token ability is required.');

        $memberships = $request->user()->scopeMemberships()
            ->where('is_active', true)
            ->get(['scope_id', 'project_access_mode']);
        $allScopeIds = $memberships->where('project_access_mode', 'all')->pluck('scope_id');
        $restrictedScopeIds = $memberships->where('project_access_mode', 'restricted')->pluck('scope_id');

        $query = Task::query()
            ->where('delegated_agent_id', $request->user()->id)
            ->where('is_agent_delegatable', true)
            ->where(function ($access) use ($allScopeIds, $request, $restrictedScopeIds): void {
                $access->whereIn('scope_id', $allScopeIds)
                    ->orWhere(function ($restricted) use ($request, $restrictedScopeIds): void {
                        $restricted->whereIn('scope_id', $restrictedScopeIds)
                            ->whereHas('project.members', fn ($members) => $members
                                ->where('user_id', $request->user()->id)
                                ->where('is_active', true));
                    });
            });

        if (! $request->boolean('include_closed')) {
            $query->whereNotIn('status', ['done', 'cancelled']);
        }

        return TaskResource::collection($query
            ->with(['scope:id,name,slug', 'project:id,title,key,color', 'assignee:id,name,type', 'delegatedAgent:id,name,type'])
            ->orderByRaw('CASE priority WHEN 5 THEN 1 WHEN 4 THEN 2 WHEN 3 THEN 3 WHEN 2 THEN 4 ELSE 5 END')
            ->orderBy('due_at')
            ->paginate());
    }
}
