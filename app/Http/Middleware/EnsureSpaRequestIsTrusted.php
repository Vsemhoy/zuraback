<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSpaRequestIsTrusted
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            hash_equals((string) config('spa.request_header'), (string) $request->header('X-App-Request')),
            Response::HTTP_FORBIDDEN,
            'Untrusted application request.',
        );

        abort_if(
            $request->header('Sec-Fetch-Site') === 'cross-site',
            Response::HTTP_FORBIDDEN,
            'Cross-site requests are not allowed.',
        );

        $origin = $request->header('Origin');

        if ($origin !== null) {
            $allowedOrigins = config('spa.allowed_origins', []);
            abort_unless(in_array(rtrim($origin, '/'), $allowedOrigins, true), Response::HTTP_FORBIDDEN, 'Origin is not allowed.');
        }

        if (! $request->isMethodSafe()) {
            abort_unless($request->isJson(), Response::HTTP_UNSUPPORTED_MEDIA_TYPE, 'JSON requests are required.');
        }

        return $next($request);
    }
}
