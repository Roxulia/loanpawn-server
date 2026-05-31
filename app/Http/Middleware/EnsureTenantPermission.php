<?php

namespace App\Http\Middleware;

use App\Services\TenantModule\TenantUserPermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantPermission
{
    public function __construct(
        private TenantUserPermissionService $permissionService,
    ) {
    }

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $permissions = array_values(array_filter($permissions));

        if (count($permissions) === 1) {
            $this->permissionService->authorizePermission($permissions[0]);
        } else {
            $this->permissionService->authorizeAnyPermission($permissions);
        }

        return $next($request);
    }
}
