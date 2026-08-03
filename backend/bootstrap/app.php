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
        // Trust the platform's proxy chain so $request->ip() is the real client
        // and not the load balancer.
        //
        // Why this is needed: in production the app is fronted by Cloudflare and
        // then by Render's own router (confirmed on the deployed service —
        // `Server: cloudflare` + `rndr-id` response headers), so REMOTE_ADDR is
        // always a Render-internal address. Laravel trusts *no* proxy by
        // default, so without this every caller resolves to that same internal
        // IP — which would make the article watermark's IP field (cahier des
        // charges §10.3) identical for every reader and therefore worthless.
        //
        // The `at:` value is a full-range CIDR list and NOT Laravel's `'*'`.
        // This is the subtle part: `'*'` does not mean "trust every proxy", it
        // means "trust the immediate caller only" (TrustProxies::
        // setTrustedProxyIpAddressesToTheCallingIp). With two hops in front of
        // us that trusts Render's router, leaves Cloudflare's edge as the
        // right-most untrusted entry in X-Forwarded-For, and stamps a Cloudflare
        // edge address on every watermark — the same bug in a new disguise.
        // Trusting the whole range makes Symfony walk past every proxy hop and
        // return the originating client, which is the address §10.3 is asking
        // for. Covered by tests/Feature/ClientIpWatermarkTest.
        //
        // Hardcoding the platform's real ranges instead was rejected: neither
        // Cloudflare's edge ranges nor Render's internal router addresses are
        // pinned for this service, so the list would rot silently and land us
        // back on a constant proxy IP with no failing test to say so.
        //
        // The accepted trade-off: with the chain fully trusted a caller can put
        // anything in X-Forwarded-For, so this IP is an audit trace, not an
        // authentication factor. Nothing authorizes off it — it is displayed in
        // a watermark and that is all.
        $middleware->trustProxies(
            at: ['0.0.0.0/0', '2000::/3'],
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

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