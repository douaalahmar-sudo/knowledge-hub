<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$roles
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user();

        // If user is not authenticated or has no role matching the allowed list
        if (! $user || ! $user->role || ! in_array($user->role->name, $roles)) {
            return response()->json([
                'message' => 'Accès refusé. Vous n\'avez pas les permissions nécessaires.'
            ], 403);
        }

        return $next($request);
    }
}