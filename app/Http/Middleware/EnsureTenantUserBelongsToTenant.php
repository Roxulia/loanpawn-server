<?php

namespace App\Http\Middleware;

use App\Models\CoreModule\TenantUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantUserBelongsToTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('tenantuser')->user() ?? $request->user();
        $tenant = $request->attributes->get('tenant');

        if ($user instanceof TenantUser && $tenant && $user->tenant_id !== $tenant->id) {
            return new JsonResponse([
                'message' => 'Unauthorized access.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
