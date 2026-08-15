<?php

namespace App\Http\Controllers\PlatformModule\Admin;

use App\DataObjects\RequestObjects\AdminLicenseGrant;
use App\DataObjects\RequestObjects\AdminTenantProvision;
use App\Http\Controllers\Controller;
use App\Services\PlatformModule\AdminTenantLicenseGrantService;
use App\Services\PlatformModule\AdminTenantProvisioningService;
use App\Services\PlatformModule\PackageService;
use App\Services\PlatformModule\TenantServices\TenantManagementService;
use Illuminate\Http\RedirectResponse;
use App\Models\PlatformModule\Tenant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminTenantManagementController extends Controller
{
    public function __construct(
        private TenantManagementService $tenantManagementService,
        private AdminTenantProvisioningService $adminTenantProvisioningService,
        private AdminTenantLicenseGrantService $adminTenantLicenseGrantService,
        private PackageService $packageService,
    ) {
    }

    public function index(): View
    {
        return view('platform.admin.tenants.index', [
            'tenants' => $this->tenantManagementService->paginateAllForAdmin(),
            'plans' => $this->packageService->activeCategoriesWithPlans()->flatMap->packages,
        ]);
    }

    public function create(): View
    {
        return view('platform.admin.tenants.create', $this->adminTenantProvisioningService->formOptions());
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'platform_user_id' => ['required', 'integer', Rule::exists('platform_users', 'id')->where('status', 'active')],
            'name' => ['required', 'string', 'max:255'],
            'subdomain' => ['nullable', 'string', 'max:63', 'alpha_dash', 'unique:tenants,subdomain'],
            'category_id' => ['required', 'integer', Rule::exists('tenant_categories', 'id')->where('is_active', true)->where('is_deleted', false)],
            'plan_id' => ['required', 'integer', Rule::exists('packages', 'id')->where('is_active', true)->where('is_deleted', false)],
            'license_months' => ['required', 'integer', 'in:1,3,6,12'],
            'reason' => ['required', 'string', 'max:1000'],
            'address' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
        ]);

        $createdTenant = $this->adminTenantProvisioningService->provision(new AdminTenantProvision(
            platformUserId: (int) $validated['platform_user_id'],
            name: $validated['name'],
            subdomain: $validated['subdomain'] ?? null,
            categoryId: (int) $validated['category_id'],
            planId: (int) $validated['plan_id'],
            licenseMonths: (int) $validated['license_months'],
            reason: $validated['reason'],
            address: $validated['address'] ?? null,
            phone: $validated['phone'] ?? null,
            city: $validated['city'] ?? null,
            country: $validated['country'] ?? null,
        ));

        return redirect()->route('admin.tenants.index')->with(
            'status',
            "Tenant {$createdTenant->name} created with license {$createdTenant->licenseKey}.",
        );
    }

    public function changePlan(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:packages,id'],
            'effective' => ['required', 'in:immediate,scheduled'],
            'duration_months' => ['nullable', 'integer', 'in:1,3,6,12'],
            'admin_review_note' => ['required', 'string', 'max:1000'],
        ]);

        if ($validated['effective'] === 'scheduled' && empty($validated['duration_months'])) {
            return back()->with('error', 'Choose a duration for a scheduled plan change.');
        }

        $this->adminTenantLicenseGrantService->grant(new AdminLicenseGrant(
            tenantId: (int) $tenant->id,
            planId: (int) $validated['plan_id'],
            effective: $validated['effective'],
            durationMonths: isset($validated['duration_months']) ? (int) $validated['duration_months'] : null,
            reason: $validated['admin_review_note'],
        ));

        return back()->with('status', 'Tenant category and plan updated.');
    }
}
