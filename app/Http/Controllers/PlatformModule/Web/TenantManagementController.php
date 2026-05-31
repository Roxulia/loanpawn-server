<?php

namespace App\Http\Controllers\PlatformModule\Web;

use App\DataObjects\RequestObjects\TenantCreate;
use App\DataObjects\RequestObjects\TenantRequestCreate;
use App\DataObjects\RequestObjects\TenantUpdate;
use App\Http\Controllers\Controller;
use App\Services\PlatformModule\PlatformTenantPageService;
use App\Services\PlatformModule\TenantRequestService;
use App\Services\PlatformModule\TenantServices\TenantManagementService;
use App\Services\TenantModule\TenantSsoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TenantManagementController extends Controller
{
    public function __construct(
        private PlatformTenantPageService $tenantPageService,
        private TenantManagementService $tenantManagementService,
        private TenantRequestService $tenantRequestService,
        private TenantSsoService $tenantSsoService,
    ) {
    }

    public function index(): View
    {
        return view('platform.tenants.index', [
            'tenants' => $this->tenantPageService->getTenantList(),
        ]);
    }

    public function create(): View
    {
        return view('platform.tenants.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
        ]);

        $this->tenantManagementService->createTenant(new TenantCreate(
            name: $validated['name'],
            code: null,
            subdomain: null,
            createdByAdmin: false,
            planType: null,
            address: $validated['address'] ?? null,
            phone: $validated['phone'] ?? null,
            city: $validated['city'] ?? null,
            country: $validated['country'] ?? null,
        ));

        return redirect()
            ->route('platform.tenants.index')
            ->with('status', 'Tenant created successfully.');
    }

    public function edit(int $tenant): View
    {
        return view('platform.tenants.settings', [
            'tenant' => $this->tenantPageService->findOwnedTenant($tenant),
        ]);
    }

    public function update(Request $request, int $tenant): RedirectResponse
    {
        $ownedTenant = $this->tenantPageService->findOwnedTenant($tenant);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'subdomain' => ['nullable', 'string', 'max:63'],
            'address' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'primary_color' => ['nullable', 'string', 'max:20'],
            'secondary_color' => ['nullable', 'string', 'max:20'],
            'accent_color' => ['nullable', 'string', 'max:20'],
            'slip_header_text' => ['nullable', 'string'],
            'slip_footer_text' => ['nullable', 'string'],
        ]);

        $this->tenantManagementService->updateTenant(new TenantUpdate(
            tenantId: $tenant,
            updateKey: $validated['update_key'],
            name: $validated['name'] ?? null,
            subdomain: $ownedTenant->license?->plan_type === 'premium' ? ($validated['subdomain'] ?? null) : $ownedTenant->subdomain,
            code: null,
            address: $validated['address'] ?? null,
            phone: $validated['phone'] ?? null,
            city: $validated['city'] ?? null,
            country: $validated['country'] ?? null,
            primaryColor: $ownedTenant->license?->plan_type === 'premium' ? ($validated['primary_color'] ?? null) : null,
            secondaryColor: $ownedTenant->license?->plan_type === 'premium' ? ($validated['secondary_color'] ?? null) : null,
            accentColor: $ownedTenant->license?->plan_type === 'premium' ? ($validated['accent_color'] ?? null) : null,
        ));

        return redirect()
            ->route('platform.tenants.edit', $tenant)
            ->with('status', 'Tenant settings updated.');
    }

    public function requestPlanChange(Request $request, int $tenant): RedirectResponse
    {
        $this->tenantPageService->findOwnedTenant($tenant);

        $validated = $request->validate([
            'requested_plan_type' => ['required', 'string', 'in:basic,premium'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->tenantRequestService->createRequest(new TenantRequestCreate(
            tenantId: $tenant,
            requestType: 'plan_change',
            requestedPlanType: $validated['requested_plan_type'],
            note: $validated['note'] ?? null,
        ));

        return redirect()
            ->route('platform.billing.index')
            ->with('status', 'Upgrade payment request created. Submit the payment attachment from billing management.');
    }

    public function requestLicenseExtension(Request $request, int $tenant): RedirectResponse
    {
        $ownedTenant = $this->tenantPageService->findOwnedTenant($tenant);

        if ($ownedTenant->license?->plan_type === 'trial') {
            return redirect()
                ->route('platform.tenants.edit', $tenant)
                ->with('status', 'Trial tenants must upgrade before requesting license extension.');
        }

        $validated = $request->validate([
            'extension_months' => ['required', 'integer', 'in:1,3,6,12'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->tenantRequestService->createRequest(new TenantRequestCreate(
            tenantId: $tenant,
            requestType: 'extension',
            extensionMonths: (int) $validated['extension_months'],
            note: $validated['note'] ?? null,
        ));

        return redirect()
            ->route('platform.billing.index')
            ->with('status', 'License extension payment request created. Submit the payment attachment from billing management.');
    }

    public function openApp(int $tenant): RedirectResponse
    {
        return redirect()->away($this->tenantSsoService->createRedirectUrl($tenant));
    }
}
