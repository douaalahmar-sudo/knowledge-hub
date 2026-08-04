<?php

namespace App\Http\Middleware;

use App\Support\Database\AccessRoleContext;
use App\Support\Database\FilialeContext;
use BackedEnum;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Publishes the authenticated user's filiale — and their access_role, for the
 * `audit_logs` policy — onto the PostgreSQL connection, so the RLS policies
 * have something to filter on before the request reaches a controller.
 *
 * This middleware does not decide what the user may see — it only states *who*
 * they are. The filtering itself happens in the database, which is why there is
 * no Eloquent global scope anywhere in this codebase.
 */
class SetTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->resolveUser($request);
        $filialeId = $user?->getAttribute('filiale_id');

        if ($user && ! $filialeId) {
            // Not a rejection: the strict policies already hide every scoped row
            // from a session with no filiale, so this account simply sees empty
            // collections. Logged because it almost always means bad
            // provisioning rather than an intentional state.
            Log::warning('Authenticated user has no filiale_id; filiale-scoped tables will return no rows.', [
                'user_id' => $user->getAuthIdentifier(),
            ]);
        }

        // Written unconditionally, including the empty value for guests: a
        // pooled or Octane-reused connection must never inherit the filiale of
        // whoever held it last.
        FilialeContext::set($filialeId ? (string) $filialeId : '');

        // The caller's access_role, for the one policy that needs to know it —
        // `audit_logs`, where everyone may append but only admin/data_owner may
        // read (see RowLevelSecurity::enableSecurityLog()). Written under the
        // same unconditional rule, and for a sharper reason: a stale role left
        // on a pooled connection would be a privilege escalation, not just a
        // wrong tenant.
        // User casts `access_role` to App\Enums\UserRole, so getAttribute()
        // hands back an enum instance, not a string — the string branch is for
        // a model hydrated without the cast (raw query, future refactor).
        $accessRole = $user?->getAttribute('access_role');

        AccessRoleContext::set(match (true) {
            $accessRole instanceof BackedEnum => (string) $accessRole->value,
            is_string($accessRole) => $accessRole,
            default => '',
        });

        try {
            return $next($request);
        } finally {
            FilialeContext::forget();
            AccessRoleContext::forget();
        }
    }

    /**
     * Resolve the user without depending on middleware ordering.
     *
     * Registered globally, this middleware runs *before* the route's
     * `auth:sanctum`, so `$request->user()` is still null at this point.
     * Resolving the guard here primes it; `auth:sanctum` then reuses the very
     * same resolved user rather than querying again.
     */
    private function resolveUser(Request $request): ?Authenticatable
    {
        if ($user = $request->user()) {
            return $user;
        }

        foreach (['sanctum', config('auth.defaults.guard')] as $guard) {
            if (! $guard || ! config("auth.guards.{$guard}")) {
                continue;
            }

            if ($user = Auth::guard($guard)->user()) {
                return $user;
            }
        }

        return null;
    }
}
