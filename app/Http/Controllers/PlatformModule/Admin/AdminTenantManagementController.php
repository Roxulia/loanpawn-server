<?php

namespace App\Http\Controllers\PlatformModule\Admin;

use App\Http\Controllers\Controller;
use App\Repository\TenantRepository;
use Illuminate\View\View;

class AdminTenantManagementController extends Controller
{
    public function __construct(
        private TenantRepository $tenantRepository,
    ) {
    }

    public function index(): View
    {
        return view('platform.admin.tenants.index', [
            'tenants' => $this->tenantRepository->paginateAll(),
        ]);
    }
}
