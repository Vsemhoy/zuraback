<?php

use App\Http\Middleware\EnsureAgentAccount;
use App\Http\Middleware\EnsureContractorAbility;
use App\Http\Middleware\EnsureScopeMember;
use App\Http\Middleware\EnsureSpaRequestIsTrusted;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\ResolveContractorActor;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'spa.request' => EnsureSpaRequestIsTrusted::class,
            'scope.member' => EnsureScopeMember::class,
            'agent' => EnsureAgentAccount::class,
            'scope.ability' => EnsureContractorAbility::class,
            'scope.actor' => ResolveContractorActor::class,
        ]);

        $middleware->validateCsrfTokens(except: ['api/auth/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
