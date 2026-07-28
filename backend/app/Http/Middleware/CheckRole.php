<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level RBAC gate. Registered as the `role` alias in bootstrap/app.php.
 *
 * Usage: ->middleware('role:admin,hr_admin')
 *
 * Role names are the snake_case values seeded into the `roles` table by
 * DatabaseSeeder (admin, process_owner, expert_metier, responsable_departement,
 * validator, manager, operator, hr_admin, hr_user) — NOT the coarse
 * SUPER_ADMIN/HR_ADMIN labels used for grouping in the Angular UI.
 *
 * NOTE: there is deliberately no implicit `admin` bypass here. Roles must be
 * listed explicitly on each route, matching how the Gates in AppServiceProvider
 * already enumerate `admin`. Adding a blanket bypass would silently widen
 * privileges on every route that uses this middleware.
 */
class CheckRole
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$roles
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user();

        // Unauthenticated is 401, not 403 — the client may simply need to log in
        // or refresh an expired token, which is a different remedy than "you are
        // logged in but lack permission".
        if (! $user) {
            return response()->json([
                'message' => 'Non authentifié. Veuillez vous connecter.',
            ], 401);
        }

        if (! $user->role || ! in_array($user->role->name, $roles, true)) {
            return response()->json([
                'message'        => 'Accès refusé. Vous n\'avez pas les permissions nécessaires.',
                'required_roles' => array_values($roles),
            ], 403);
        }

        return $next($request);
    }
}
