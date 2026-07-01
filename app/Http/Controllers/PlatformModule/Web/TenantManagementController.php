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
use App\Services\PlatformModule\TenantServices\TenantLicenseService;
use App\Exceptions\ApiException;
use App\Utility\MessageCode;
use Illuminate\Http\JsonResponse;
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
        private TenantLicenseService $tenantLicenseService,
    ) {
    }

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        return view('platform.tenants.index', [
            'tenants' => $this->tenantPageService->getTenantList($search !== '' ? $search : null),
            'search' => $search,
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
        ], [], __('validation.attributes'));

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
            ->with('status', $this->responseMessage(MessageCode::PlatformTenantCreated, ['name' => $validated['name']]));
    }

    public function edit(int $tenant): View
    {
        $ownedTenant = $this->tenantPageService->findOwnedTenant($tenant);

        return view('platform.tenants.settings', [
            'tenant' => $ownedTenant,
            'planOptions' => $this->tenantPageService->activePaidPlansExcept($ownedTenant->license?->plan_type),
            'canManageBranding' => $this->tenantLicenseService->tenantHasFeature($ownedTenant->id, 'branding_management'),
        ]);
    }

    public function update(Request $request, int $tenant): RedirectResponse
    {
        $ownedTenant = $this->tenantPageService->findOwnedTenant($tenant);
        $canManageBranding = $this->tenantLicenseService->tenantHasFeature($tenant, 'branding_management');

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'subdomain' => ['nullable', 'string', 'max:63'],
            'address' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'primary_color' => ['nullable', 'string', 'max:7'],
            'secondary_color' => ['nullable', 'string', 'max:7'],
            'accent_color' => ['nullable', 'string', 'max:7'],
        ], [], __('validation.attributes'));

        $this->tenantManagementService->updateTenant(new TenantUpdate(
            tenantId: $tenant,
            updateKey: $validated['update_key'],
            name: $validated['name'] ?? null,
            subdomain: $canManageBranding ? ($validated['subdomain'] ?? null) : $ownedTenant->subdomain,
            code: null,
            address: $validated['address'] ?? null,
            phone: $validated['phone'] ?? null,
            city: $validated['city'] ?? null,
            country: $validated['country'] ?? null,
            primaryColor: $canManageBranding ? ($validated['primary_color'] ?? null) : null,
            secondaryColor: $canManageBranding ? ($validated['secondary_color'] ?? null) : null,
            accentColor: $canManageBranding ? ($validated['accent_color'] ?? null) : null,
        ));

        return redirect()
            ->route('platform.tenants.edit', $tenant)
            ->with('status', $this->responseMessage(MessageCode::PlatformTenantUpdated));
    }

    public function requestPlanChange(Request $request, int $tenant): RedirectResponse
    {
        try{
            $this->tenantPageService->findOwnedTenant($tenant);

            $validated = $request->validate([
                'requested_plan_type' => ['required', 'string', 'max:40'],
                'extension_months' => ['nullable', 'integer', 'in:1,3,6,12'],
                'note' => ['nullable', 'string', 'max:1000'],
            ], [], __('validation.attributes'));

            $tenantRequest = $this->tenantRequestService->createRequest(new TenantRequestCreate(
                tenantId: $tenant,
                requestType: 'plan_change',
                requestedPlanType: $validated['requested_plan_type'],
                extensionMonths: isset($validated['extension_months']) ? (int) $validated['extension_months'] : null,
                note: $validated['note'] ?? null,
            ));

            return redirect()
                ->route('platform.billing.index')
                ->with('status', $this->responseMessage(MessageCode::PlatformPlanChangeRequestCreated))
                ->with('open_payment_tenant_request_id', $tenantRequest->id);
        }
        catch (ApiException $exception) {
            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        }
    }

    public function requestLicenseExtension(Request $request, int $tenant): RedirectResponse
    {
        try {
            $ownedTenant = $this->tenantPageService->findOwnedTenant($tenant);

            if ($ownedTenant->license?->plan_type === 'trial') {
                return redirect()
                    ->route('platform.tenants.edit', $tenant)
                    ->with('status', $this->responseMessage(MessageCode::PlatformTrialUpgradeRequired));
            }

            $validated = $request->validate([
                'extension_months' => ['required', 'integer', 'in:1,3,6,12'],
                'note' => ['nullable', 'string', 'max:1000'],
            ], [], __('validation.attributes'));

            $tenantRequest = $this->tenantRequestService->createRequest(new TenantRequestCreate(
                tenantId: $tenant,
                requestType: 'extension',
                extensionMonths: (int) $validated['extension_months'],
                note: $validated['note'] ?? null,
            ));

            return redirect()
                ->route('platform.billing.index')
                ->with('status', $this->responseMessage(MessageCode::PlatformExtensionRequestCreated))
                ->with('open_payment_tenant_request_id', $tenantRequest->id);
        }
        catch (ApiException $exception) {
            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        }
    }

    public function openApp(Request $request, int $tenant): JsonResponse|RedirectResponse
    {
        try {
            $this->tenantLicenseService->ensureTenantCanOpenApp($tenant);
            $redirectUrl = $this->tenantSsoService->createRedirectUrl($tenant);

            if ($request->expectsJson()) {
                return $this->successResponse(['redirect_url' => $redirectUrl]);
            }

            return redirect()->away($redirectUrl);
        } catch (ApiException $exception) {
            if ($request->expectsJson()) {
                return $this->errorResponse(
                    message: $exception->getMessage(),
                    data: ['code' => $exception->errorCode()],
                    statusCode: $exception->statusCode(),
                );
            }

            return back()->with('error', $exception->getMessage());
        }
    }
}
