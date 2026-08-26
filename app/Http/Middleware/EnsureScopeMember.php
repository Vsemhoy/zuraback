<?php

namespace App\Http\Middleware;

use App\Models\Scope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureScopeMember
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $scope = $request->route('scope');

        if (! $scope instanceof Scope) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $user = $request->user();
        $isMember = $scope->owner_id === $user?->id
            || $scope->members()->whereBelongsTo($user)->where('is_active', true)->exists();

        abort_unless($isMember, Response::HTTP_FORBIDDEN, 'You are not a member of this scope.');

        return $next($request);
    }
}
