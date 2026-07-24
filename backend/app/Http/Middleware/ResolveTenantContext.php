<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $request->user()?->tenant_id;

        if (!$tenantId) {
            return response()->json([
                'message' => 'Contexte locataire introuvable.'
            ], 403);
        }

        $request->attributes->set('tenant_id', $tenantId);

        return $next($request);
    }
}