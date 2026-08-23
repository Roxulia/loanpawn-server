<?php

namespace App\Http\Middleware;

use App\DataObjects\RequestObjects\AppVersionRequest;
use App\Exceptions\UnsupportedFrontendVersion;
use App\Services\AppCompatibilityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSupportedFrontendVersion
{
    public function __construct(private AppCompatibilityService $compatibilityService) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || $request->is('api/tenant/logout')) {
            return $next($request);
        }

        $compatibility = $this->compatibilityService->check(new AppVersionRequest(
            installedVersion: $request->header('X-LonePawn-App-Version'),
        ));

        if (! $compatibility->isSupported) {
            throw new UnsupportedFrontendVersion($compatibility);
        }

        return $next($request);
    }
}
