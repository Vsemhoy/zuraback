<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() !== null && ! $request->user()->is_active) {
            auth()->logout();
            $request->session()->invalidate();

            abort(Response::HTTP_UNAUTHORIZED, 'This account is disabled.');
        }

        return $next($request);
    }
}
