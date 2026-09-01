<?php

namespace App\Http\Middleware;

use App\Models\Project;
use App\Models\Scope;
use App\Models\Task;
use App\Services\ContractorAccessService;
use App\Services\ContractorContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureContractorAbility
{
    public function __construct(
        private readonly ContractorAccessService $access,
        private readonly ContractorContext $context,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $scope = $request->route('scope');
        abort_unless($scope instanceof Scope, Response::HTTP_NOT_FOUND);

        $project = $request->route('project');
        $task = $request->route('task');

        if (! $project instanceof Project && $task instanceof Task && $task->project_id !== null) {
            $project = Project::query()->find($task->project_id);
        }

        $actor = $this->context->actor($request);
        $canAccessUnprojected = ! $task instanceof Task || $task->project_id !== null || $this->access->canAccessUnprojected($actor, $scope);

        abort_unless(
            $canAccessUnprojected && $this->access->allows($actor, $scope, $ability, $project instanceof Project ? $project : null),
            Response::HTTP_FORBIDDEN,
            "The {$ability} capability is required.",
        );

        return $next($request);
    }
}
