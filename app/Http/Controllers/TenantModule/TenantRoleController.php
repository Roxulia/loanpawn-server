<?php

namespace App\Http\Controllers\TenantModule;

use App\Http\Controllers\Controller;
use App\Services\TenantModule\TenantRoleService;

class TenantRoleController extends Controller
{
    public function __construct(
        private TenantRoleService $tenantRoleService,
    ) {
    }

    public function index()
    {
        return $this->successResponse($this->tenantRoleService->listOptions());
    }
}
