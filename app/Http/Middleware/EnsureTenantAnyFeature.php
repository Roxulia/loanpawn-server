<?php

namespace App\Http\Middleware;

use App\Services\PlatformModule\TenantServices\TenantLicenseService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantAnyFeature
{
    public function __construct(private TenantLicenseService $tenantLicenseService) {}

    public function handle(Request $request, Closure $next, string ...$featureCodes): Response
    {
        $this->tenantLicenseService->ensureCurrentTenantHasAnyFeature($featureCodes);

        return $next($request);
    }
}
