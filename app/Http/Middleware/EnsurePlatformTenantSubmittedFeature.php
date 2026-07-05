<?php

namespace App\Http\Middleware;

use App\Services\PlatformModule\PlatformTenantPageService;
use App\Services\PlatformModule\TenantServices\TenantLicenseService;
use App\Utility\MessageCode;
use App\Utility\Messages;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformTenantSubmittedFeature
{
    public function __construct(
        private PlatformTenantPageService $tenantPageService,
        private TenantLicenseService $tenantLicenseService,
        private Messages $messages,
    ) {
    }

    public function handle(Request $request, Closure $next, string $featureCode, string $field): Response
    {
        if (! $request->exists($field)) {
            return $next($request);
        }

        $tenantId = (int) $request->route('tenant');
        $tenant = $this->tenantPageService->findOwnedTenant($tenantId);

        if (! $this->tenantLicenseService->tenantHasFeature($tenant->id, $featureCode)) {
            return back()
                ->withInput($request->except($field))
                ->with('error', $this->messages->responseMessage(MessageCode::ExceptionFeatureNotAvailableForPlan));
        }

        return $next($request);
    }
}
