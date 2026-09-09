<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuditAgentApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->record($request, method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 500, $startedAt);
            throw $exception;
        }

        $this->record($request, $response->getStatusCode(), $startedAt);

        return $response;
    }

    private function record(Request $request, int $status, float $startedAt): void
    {
        $agent = $request->user();
        if (! $agent?->isAgent()) return;

        $token = $agent->currentAccessToken();
        $scope = $request->route('scope');
        $routeParameters = collect($request->route()?->parameters() ?? [])->map(function (mixed $value): mixed {
            if ($value instanceof Model) return $value->getRouteKey();
            return is_scalar($value) || $value === null ? $value : get_debug_type($value);
        })->all();
        $isWrite = ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);

        try {
            ActivityLog::query()->create([
                'scope_id' => $scope instanceof Model ? $scope->getKey() : (is_string($scope) ? $scope : null),
                'actor_id' => $agent->id,
                'subject_type' => 'agent_api',
                'subject_id' => $agent->id,
                'action' => 'agent.api.'.strtolower($request->method()),
                'after' => [
                    'status' => $status,
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ],
                'context' => [
                    'mutation' => $isWrite,
                    'token_id' => $token?->getKey(),
                    'token_name' => $token?->name,
                    'token_comment' => $token?->comment,
                    'path' => '/'.$request->path(),
                    'controller' => $request->route()?->getActionName(),
                    'route_parameters' => $routeParameters,
                    'query' => $this->sanitize($request->query()),
                    'payload' => $isWrite ? $this->sanitize($request->all()) : null,
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        } catch (Throwable) {
            // Auditing must never change the outcome of the agent request itself.
        }
    }

    private function sanitize(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match('/token|password|secret|authorization|cookie|credential|api.?key/i', $key)) return '[redacted]';
        if (is_array($value)) {
            return collect($value)->mapWithKeys(fn (mixed $item, string|int $itemKey): array => [
                $itemKey => $this->sanitize($item, (string) $itemKey),
            ])->all();
        }
        if (is_string($value) && mb_strlen($value) > 4000) {
            return mb_substr($value, 0, 4000).'… [truncated; sha256='.hash('sha256', $value).']';
        }
        if (is_scalar($value) || $value === null) return $value;

        return '['.get_debug_type($value).']';
    }
}
