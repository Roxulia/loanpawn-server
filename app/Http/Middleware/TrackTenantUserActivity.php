<?php

namespace App\Http\Middleware;

use App\Models\CoreModule\TenantUser;
use App\Services\TenantModule\AuthService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class TrackTenantUserActivity
{
    public function __construct(
        private AuthService $authService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('tenantuser')->user() ?? $request->user();

        if ($user instanceof TenantUser) {
            $this->authService->recordAuthenticatedActivity($user);
        }

        return $next($request);
    }
}
