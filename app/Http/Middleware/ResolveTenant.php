<?php

namespace App\Http\Middleware;

use App\Models\CoreModule\TenantUser;
use App\Models\PlatformModule\Tenant;
use App\Services\PlatformModule\TenantServices\TenantLookupService;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolveTenant($request);

        if (! $tenant) {
            return new JsonResponse([
                'message' => 'Unauthorized access.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        /** @var TenantContext $tenantContext */
        $tenantContext = app(TenantContext::class);
        $tenantContext->set($tenant);

        $request->attributes->set('tenant', $tenant);

        try {
            return $next($request);
        } finally {
            $tenantContext->clear();
        }
    }

    protected function resolveTenant(Request $request): ?Tenant
    {
        $tenantLookupService = app(TenantLookupService::class);
        $this->setAuthenticatedTenantIdFromCookie($request);
        $authenticatedUser = Auth::guard('tenantuser')->user() ?? $request->user();
        $cookieTenantId = $request->attributes->get('authenticated_tenant_id');

        if ($cookieTenantId) {
            return $tenantLookupService->findById((int) $cookieTenantId);
        }

        if ($authenticatedUser) {
            $tenantId = null;
            if ($authenticatedUser instanceof TenantUser) {
                $tenantId = $authenticatedUser->tenant_id;
            }

            if ($tenantId) {
                return $tenantLookupService->findById((int) $tenantId);
            }
        }

        $tenantCode = $request->header('X-Tenant-Code');
        if ($tenantCode) {
            return $tenantLookupService->findByTenantCode($tenantCode);
        }

        $licenseKey = $request->header('X-License-Key');
        if ($licenseKey) {
            return $tenantLookupService->findByLicenseKey($licenseKey);
        }

        $tenantHost = $request->header('X-Tenant-Host');
        if ($tenantHost) {
            $subdomain = $this->extractSubdomainFromHost($tenantHost);
            if ($subdomain && $subdomain !== 'app') {
                return $tenantLookupService->findBySubDomain($subdomain);
            }
        }

        $subdomain = $this->extractSubdomainFromOrigin($request->headers->get('Origin'));
        if ($subdomain && $subdomain !== 'app') {
            return $tenantLookupService->findBySubDomain($subdomain);
        }

        return null;
    }

    protected function setAuthenticatedTenantIdFromCookie(Request $request): void
    {
        if ($request->attributes->get('authenticated_tenant_id')) {
            return;
        }

        $cookie = $request->cookie('tenant_auth_token');

        if (! is_string($cookie) || $cookie === '') {
            return;
        }

        $payload = json_decode($cookie, true);

        if (
            ! is_array($payload)
            || ! isset($payload['tenantId'])
            || ! is_numeric($payload['tenantId'])
        ) {
            return;
        }

        $request->attributes->set('authenticated_tenant_id', (int) $payload['tenantId']);
    }

    protected function extractSubdomainFromOrigin(?string $origin): ?string
    {
        if (! $origin) {
            return null;
        }

        $host = parse_url($origin, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return $this->extractSubdomainFromHost($host);
    }

    protected function extractSubdomainFromHost(?string $host): ?string
    {
        if (! is_string($host) || $host === '') {
            return null;
        }

        $segments = explode('.', $host);

        if (count($segments) < 3) {
            return null;
        }

        return strtolower($segments[0]);
    }
}
