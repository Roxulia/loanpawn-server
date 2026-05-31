<?php

namespace App\Http\Controllers\PlatformModule;

use App\Http\Controllers\Controller;
use App\Services\PlatformModule\TenantServices\TenantDetailService;
use App\Services\PlatformModule\TenantServices\TenantManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TenantController extends Controller
{

    public function __construct(
        private TenantManagementService $tenantManagementService,
        private TenantDetailService $tenantDetailService
    )
    {

    }

    public function showTenantList() : View
    {
        $tenants = $this->tenantManagementService->all()->toArray();
        return view('platform.admin.tenant.list',compact('tenants'));
    }

    public function showUserTenantList():View
    {
        $tenants = $this->tenantManagementService->listByPlatformUser()->toArray();
        return view('platform.tenant.list',compact('tenants'));
    }

    public function showTenantDetail($tenantId) : View
    {
        $tenantDetail = $this->tenantDetailService->findByTenantId($tenantId);
        return view('platform.tenant.detail',compact('tenantDetail'));
    }

    public function resolveTenant() : JsonResponse
    {
        $res = $this->tenantDetailService->getCurrentTenant();
        return response()->json([
            'data' => $res->toArray(),
            ],200
        );
    }

    public function showTenantLogo(string $tenantCode): StreamedResponse
    {
        return $this->tenantDetailService->getTenantLogoImage($tenantCode);
    }

    public function showTenantCreatePage(Request $request)
    {
        return view('platform.tenant.create', compact('tenant'));
    }
}
