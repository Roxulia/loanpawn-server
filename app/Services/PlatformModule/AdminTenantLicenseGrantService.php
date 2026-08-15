<?php

namespace App\Services\PlatformModule;

use App\DataObjects\RequestObjects\AdminLicenseGrant;
use App\Exceptions\TenantNotFound;
use App\Repository\TenantRepository;
use App\Services\PlatformModule\TenantServices\TenantLicenseService;
use Illuminate\Support\Facades\DB;

class AdminTenantLicenseGrantService
{
    public function __construct(
        private TenantRepository $repository,
        private TenantLicenseService $tenantLicenseService,
        private TenantRequestService $tenantRequestService,
        private PackageService $packageService,
        private AuthService $authService,
    ) {}

    public function grant(AdminLicenseGrant $request): void
    {
        DB::transaction(function () use ($request): void {
            $tenant = $this->repository->findByIdForUpdate($request->tenantId);
            if (! $tenant) {
                throw new TenantNotFound('Tenant not found.');
            }

            $plan = $this->packageService->findActiveById($request->planId);
            $license = $this->tenantLicenseService->getTenantLicenseForUpdate($tenant->id);
            $this->tenantLicenseService->ensureTenantHasNoScheduledPlanTransition($tenant->id);
            $adminId = (int) $this->authService->getCurrentUser('platformadmin')->id;
            $previousPlanId = $license->plan_id;
            $previousPlanType = $license->plan?->code ?? $license->plan_type;
            $previousExpiry = $license->expires_at;
            $startsAt = $request->effective === 'scheduled'
                ? ($previousExpiry ?? now())
                : ($license->starts_at ?? now());
            $expiresAt = $request->effective === 'scheduled'
                ? $startsAt->copy()->addMonths((int) $request->durationMonths)
                : ($previousExpiry ?? now());

            $tenantRequest = $this->tenantRequestService->createAdminApprovedGrant(
                tenant: $tenant,
                plan: $plan,
                adminId: $adminId,
                reason: $request->reason,
                extensionMonths: $request->durationMonths,
                businessInfo: [
                    'action' => 'plan_grant',
                    'effective' => $request->effective,
                    'previous_plan_id' => $previousPlanId,
                    'new_plan_id' => $plan->id,
                    'previous_expires_at' => $previousExpiry?->toIso8601String(),
                    'new_expires_at' => $expiresAt->toIso8601String(),
                ],
            );

            $isImmediate = $request->effective === 'immediate';
            $this->tenantLicenseService->createPlanTransition([
                'tenant_license_id' => $license->id,
                'tenant_request_id' => $tenantRequest->id,
                'from_plan_id' => $previousPlanId,
                'to_plan_id' => $plan->id,
                'from_plan_type' => $previousPlanType,
                'to_plan_type' => $plan->code,
                'starts_at' => $startsAt,
                'expires_at' => $expiresAt,
                'status' => $isImmediate ? 'activated' : 'scheduled',
                'approved_by' => $adminId,
                'activated_at' => $isImmediate ? now() : null,
            ]);

            if (! $isImmediate) {
                return;
            }

            $oldStatus = $license->status;
            $this->tenantLicenseService->updateLicense($license, [
                'plan_id' => $plan->id,
                'plan_type' => $plan->code,
                'status' => 'active',
                'approved_by' => $adminId,
                'notes' => $request->reason,
                'update_key' => $license->update_key + 1,
            ]);
            $this->repository->update($tenant, [
                'category_id' => $plan->category_id,
                'update_key' => $tenant->update_key + 1,
            ]);
            $this->tenantLicenseService->createStatusLog([
                'license_id' => $license->id,
                'old_status' => $oldStatus,
                'new_status' => 'active',
                'changed_by' => $adminId,
                'reason' => 'Admin free plan grant: '.$request->reason,
            ]);
        });
    }
}
