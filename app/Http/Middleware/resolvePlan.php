<?php

namespace App\Http\Middleware;

use App\Models\PlatformModule\TenantLicense;
use App\Services\PlatformModule\TenantServices\TenantLicenseService;
use App\Support\TenantContext;
use App\Utility\MessageCode;
use App\Utility\Messages;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class resolvePlan
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next,string $plan): Response
    {

        $currentLicense = app(TenantLicenseService::class)->getCurrentTenantLicense();
        if (!$currentLicense || $currentLicense->plan_type !== $plan) {
            return response()->json(['message' => app(Messages::class)->responseMessage(MessageCode::MiddlewarePlanFeatureDenied)], 403);
        }
        return $next($request);
    }
}
