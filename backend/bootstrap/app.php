<?php

use App\Http\Middleware\CheckRole;
use App\Http\Middleware\SetTenantContext;
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
        // Publish the caller's filiale onto the PostgreSQL connection for every
        // single request, so the RLS policies have a value to filter on before
        // any query runs. Appended to the *global* stack rather than a route
        // group: nothing should be able to reach the database without having
        // gone through it. It resolves the Sanctum guard itself, so running
        // ahead of `auth:sanctum` is fine (see SetTenantContext::resolveUser).
        $middleware->append(SetTenantContext::class);

        // Register route middleware alias for RBAC
        $middleware->alias([
            'role' => CheckRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();