<?php

namespace App\Http\Middleware;

use App\Services\PlatformModule\TenantServices\TenantLicenseService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantFeature
{
    public function __construct(
        private TenantLicenseService $tenantLicenseService,
    ) {
    }

    public function handle(Request $request, Closure $next, string $featureCode): Response
    {
        $this->tenantLicenseService->ensureCurrentTenantHasFeature($featureCode);

        return $next($request);
    }
}
