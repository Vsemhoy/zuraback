<?php

namespace App\Http\Middleware;

use App\Models\Scope;
use App\Services\ScopeModuleService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureScopeModule
{
    public function handle(Request $request, Closure $next): Response
    {
        $scope = $request->route('scope');
        abort_unless($scope instanceof Scope, 404);
        $path = trim(explode('{scope}', $request->route()->uri(), 2)[1] ?? '', '/');
        $section = explode('/', $path)[0];
        $module = match ($section) {
            'tasks' => 'tasker', 'planner' => 'calendar', 'plans' => 'planner',
            'kpis', 'kpi-profiles' => 'kpi', 'reports' => 'reporter',
            'events', 'event-types', 'event-sections' => 'eventor',
            'contacts' => 'contactor', 'facts' => 'factor', 'lore' => 'lore', 'darklogs' => 'darklog',
            'books', 'book-spaces', 'book-comments' => 'booker',
            'diagrams', 'lightbooks' => 'content', 'equip' => 'equip',
            'exploiter' => 'exploiter',
            'ledger' => 'ledger',
            'imports' => str_ends_with($path, '/booker') ? 'booker' : 'tasker',
            default => null,
        };
        $required = $module ? [$module] : [];
        if ($section === 'equip' && str_contains($path, '/tasks')) {
            $required[] = 'tasker';
        }
        if ($section === 'darklogs' && str_contains($path, '/kpi')) {
            $required[] = 'kpi';
        }
        foreach ($required as $id) {
            if (! ScopeModuleService::enabled($scope, $id)) {
                return response()->json(['message' => 'Модуль отключён владельцем скоупа.', 'code' => 'module_disabled', 'module' => $id], 403);
            }
        }

        return $next($request);
    }
}
