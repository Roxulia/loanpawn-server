<?php

namespace App\Services\PlatformModule;

use App\DataObjects\RequestObjects\AdminTenantProvision;
use App\DataObjects\RequestObjects\TenantCreate;
use App\DataObjects\ResponseObjects\AdminTenantProvisionResult;
use App\Exceptions\InvalidTenantRequest;
use App\Services\PlatformModule\TenantServices\TenantManagementService;
use Illuminate\Support\Facades\DB;

class AdminTenantProvisioningService
{
    private const SUBDOMAIN_FEATURE = 'subdomain_available';

    public function __construct(
        private TenantManagementService $tenantManagementService,
        private PlatformUserService $platformUserService,
        private PackageService $packageService,
        private TenantRequestService $tenantRequestService,
        private AuthService $authService,
    ) {}

    public function formOptions(): array
    {
        $categories = $this->packageService->activeCategoriesWithPlans()
            ->each(function ($category): void {
                $category->setRelation(
                    'packages',
                    $category->packages
                        ->filter(fn ($plan): bool => $this->packageService->planHasFeature(
                            $plan->code,
                            self::SUBDOMAIN_FEATURE,
                        ))
                        ->values(),
                );
            })
            ->filter(fn ($category): bool => $category->packages->isNotEmpty())
            ->values();

        return [
            'owners' => $this->platformUserService->activeOptions(),
            'categories' => $categories,
        ];
    }

    public function provision(AdminTenantProvision $request): AdminTenantProvisionResult
    {
        $owner = $this->platformUserService->findById($request->platformUserId);
        if ($owner->status !== 'active') {
            throw new InvalidTenantRequest('The selected tenant owner must be active.');
        }

        $plan = $this->packageService->findActiveById($request->planId);
        if ((int) $plan->category_id !== $request->categoryId) {
            throw new InvalidTenantRequest('Select a plan belonging to the chosen tenant category.');
        }

        if (! $this->packageService->planHasFeature($plan->code, self::SUBDOMAIN_FEATURE)) {
            throw new InvalidTenantRequest('The selected plan does not support tenant subdomains.');
        }

        $disallowedSubdomains = array_map(
            static fn (mixed $subdomain): string => strtolower(trim((string) $subdomain)),
            (array) config('app.disallowed_subdomains', []),
        );

        if ($request->subdomain !== null
            && in_array(strtolower(trim($request->subdomain)), $disallowedSubdomains, true)) {
            throw new InvalidTenantRequest('The selected subdomain is not available.');
        }

        $adminId = (int) $this->authService->getCurrentUser('platformadmin')->id;

        return DB::transaction(function () use ($request, $plan, $adminId): AdminTenantProvisionResult {
            $tenant = $this->tenantManagementService->createTenant(new TenantCreate(
                name: $request->name,
                code: null,
                subdomain: $request->subdomain,
                createdByAdmin: true,
                planType: $plan->code,
                status: 'active',
                platformUserId: $request->platformUserId,
                expireAt: now()->addMonths($request->licenseMonths)->toDateTimeString(),
                notes: $request->reason,
                address: $request->address,
                phone: $request->phone,
                city: $request->city,
                country: $request->country,
                categoryId: $request->categoryId,
                planId: $plan->id,
            ));

            $this->tenantRequestService->createAdminApprovedGrant(
                tenant: $tenant,
                plan: $plan,
                adminId: $adminId,
                reason: $request->reason,
                extensionMonths: $request->licenseMonths,
                businessInfo: [
                    'action' => 'tenant_creation',
                    'effective' => 'immediate',
                    'previous_plan_id' => null,
                    'new_plan_id' => $plan->id,
                    'previous_expires_at' => null,
                    'new_expires_at' => $tenant->license->expires_at->toIso8601String(),
                ],
            );

            return AdminTenantProvisionResult::fromModel($tenant);
        });
    }
}
