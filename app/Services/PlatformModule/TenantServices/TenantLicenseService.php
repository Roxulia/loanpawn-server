<?php

namespace App\Services\PlatformModule\TenantServices;

use App\DataObjects\RequestObjects\TenantCreate;
use App\DataObjects\ResponseObjects\LicenseValidationResult;
use App\Exceptions\FeatureNotAvailableForPlan;
use App\Exceptions\InvalidTenantRequest;
use App\Exceptions\PremiumPlanRequired;
use App\Exceptions\TenantNotFound;
use App\Mail\TenantLicenseExpiringMail;
use App\Models\PlatformModule\LicenseStatusLog;
use App\Models\PlatformModule\TenantLicense;
use App\Models\PlatformModule\TenantRequest;
use App\Repository\TenantLicenseRepository;
use App\Services\BaseTenantService;
use App\Services\PlatformModule\AuthService;
use App\Services\PlatformModule\PackageService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class TenantLicenseService extends BaseTenantService
{
    private const NOTIFICATION_LICENSE_EXPIRING = 'license_expiring';

    public function __construct(
        private TenantLicenseRepository $repository,
        private AuthService $authService,
        private PackageService $packageService
    ) {}

    public function getCurrentTenantLicense(): TenantLicense
    {
        return $this->getTenantLicense($this->resolveCurrentTenantId());
    }

    public function getTenantLicense(int $tenantId): TenantLicense
    {
        $license = $this->repository->findByTenantId($tenantId);

        if (! $license) {
            throw new TenantNotFound('Tenant license not found.');
        }

        return $license;
    }

    public function validateLicenseKey(string $licenseKey): LicenseValidationResult
    {
        $license = $this->repository->findByLicenseKey($licenseKey);

        if ($license === null || $license->tenant === null) {
            return LicenseValidationResult::invalid('License key was not found.');
        }

        if ($license->status !== 'active') {
            return LicenseValidationResult::invalid('License is not active.', $license);
        }

        if ($license->expires_at === null || $license->expires_at->lte(now())) {
            return LicenseValidationResult::invalid('License is expired.', $license);
        }

        return LicenseValidationResult::valid($license);
    }

    public function currentTenantHasFeature(string $featureCode): bool
    {
        return $this->tenantHasFeature($this->resolveCurrentTenantId(), $featureCode);
    }

    public function tenantHasFeature(int $tenantId, string $featureCode): bool
    {
        $license = $this->getTenantLicense($tenantId);

        return $this->packageService->planHasFeature($license->plan_type, $featureCode);
    }

    public function ensureCurrentTenantHasFeature(string $featureCode): TenantLicense
    {
        return $this->ensureTenantHasFeature($this->resolveCurrentTenantId(), $featureCode);
    }

    public function ensureTenantHasFeature(int $tenantId, string $featureCode): TenantLicense
    {
        $license = $this->getTenantLicense($tenantId);

        if (! $this->packageService->planHasFeature($license->plan_type, $featureCode)) {
            throw new FeatureNotAvailableForPlan;
        }

        return $license;
    }

    public function getCurrentTenantFeatureValue(string $featureCode): ?string
    {
        $license = $this->getCurrentTenantLicense();

        return $this->packageService->featureValue($license->plan_type, $featureCode);
    }

    public function ensureCurrentTenantHasPremiumPlan(): TenantLicense
    {
        return $this->ensureTenantHasPremiumPlan($this->resolveCurrentTenantId());
    }

    public function ensureTenantHasPremiumPlan(int $tenantId): TenantLicense
    {
        $license = $this->getTenantLicense($tenantId);

        if ($license->plan_type !== 'premium') {
            throw new PremiumPlanRequired;
        }

        return $license;
    }

    public function createLicense($tenantId, $approvedBy, TenantCreate $request): TenantLicense
    {
        $issuedAt = now();
        $startsAt = $request->status === 'active' ? $issuedAt : null;
        $activatedAt = $request->status === 'active' ? $issuedAt : null;
        $expiresAt = $request->expireAt
            ? Carbon::parse($request->expireAt)
            : $issuedAt->copy()->addMonths(3);
        $license = TenantLicense::query()->create([
            'tenant_id' => $tenantId,
            'license_key' => $this->generateLicenseKey(),
            'plan_type' => $request->planType,
            'status' => $request->status,
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
            'activated_at' => $activatedAt,
            'approved_by' => $approvedBy,
            'notes' => $request->notes,
        ]);

        return $license;
    }

    public function createStatusLog(array $data): LicenseStatusLog
    {
        return LicenseStatusLog::query()->create($data);
    }

    public function applyApprovedTenantRequest(TenantRequest $tenantRequest, int $approvedBy, ?string $adminReviewNote = null): TenantLicense
    {
        $license = $this->getTenantLicense((int) $tenantRequest->tenant_id);
        $oldStatus = $license->status;
        $data = [
            'status' => 'active',
            'approved_by' => $approvedBy,
            'notes' => $adminReviewNote ?? $license->notes,
            'update_key' => $license->update_key + 1,
        ];

        if ($tenantRequest->request_type === 'extension') {
            $baseDate = $license->expires_at ?? now();
            $data['expires_at'] = $baseDate->copy()->addMonths((int) $tenantRequest->extension_months);
        }

        if (
            $tenantRequest->request_type === 'plan_change'
            && $license->plan_type === 'premium'
            && $tenantRequest->requested_plan_type === 'basic'
        ) {
            $this->scheduleDowngrade($license, $tenantRequest, $approvedBy);
        } elseif ($tenantRequest->request_type === 'plan_change') {
            $data['plan_type'] = $tenantRequest->requested_plan_type;
        }

        if ($license->starts_at === null) {
            $data['starts_at'] = now();
        }

        if ($license->activated_at === null) {
            $data['activated_at'] = now();
        }

        $license->update($data);

        if ($oldStatus !== $license->refresh()->status) {
            $this->createStatusLog([
                'license_id' => $license->id,
                'old_status' => $oldStatus,
                'new_status' => $license->status,
                'changed_by' => $approvedBy,
                'reason' => $adminReviewNote ?? 'Tenant request approved',
            ]);
        }

        return $license->refresh();
    }

    public function checkExpire(): int
    {
        return $this->runLoggedOperation(__METHOD__, function (): int {
            $this->repository->activateDuePlanTransitions();

            return $this->repository->checkExpire();
        });
    }

    public function ensureTenantHasNoScheduledPlanTransition(int $tenantId): void
    {
        $license = $this->getTenantLicense($tenantId);

        if ($this->repository->hasScheduledTransition($license->id)) {
            throw new InvalidTenantRequest('Resolve the scheduled plan change before extending this license.');
        }
    }

    protected function scheduleDowngrade(
        TenantLicense $license,
        TenantRequest $tenantRequest,
        int $approvedBy
    ): void {
        if ($this->repository->hasScheduledTransition($license->id)) {
            throw new InvalidTenantRequest('A scheduled plan change already exists for this license.');
        }

        $startsAt = $license->expires_at;

        if ($startsAt === null || $tenantRequest->extension_months === null) {
            throw new InvalidTenantRequest('Scheduled downgrade term is required.');
        }

        $this->repository->createPlanTransition([
            'tenant_license_id' => $license->id,
            'tenant_request_id' => $tenantRequest->id,
            'from_plan_type' => $license->plan_type,
            'to_plan_type' => $tenantRequest->requested_plan_type,
            'starts_at' => $startsAt,
            'expires_at' => $startsAt->copy()->addMonths((int) $tenantRequest->extension_months),
            'status' => 'scheduled',
            'approved_by' => $approvedBy,
        ]);
    }

    public function sendExpiringSoonNotifications(int $thresholdDays = 7): int
    {
        return $this->runLoggedOperation(__METHOD__, function () use ($thresholdDays): int {
            $sent = 0;
            $billingUrl = route('platform.billing.index');

            foreach ($this->repository->activeLicensesExpiringInDays($thresholdDays) as $license) {
                if ($this->repository->hasNotificationLog($license->id, self::NOTIFICATION_LICENSE_EXPIRING, $thresholdDays)) {
                    continue;
                }

                $ownerEmail = $license->tenant?->owner?->email;

                if ($ownerEmail === null || $ownerEmail === '') {
                    continue;
                }

                Mail::to($ownerEmail)->send(new TenantLicenseExpiringMail($license, $billingUrl));
                $this->repository->createNotificationLog($license->id, self::NOTIFICATION_LICENSE_EXPIRING, $thresholdDays);
                $sent++;
            }

            return $sent;
        });
    }

    protected function generateLicenseKey(): string
    {
        do {
            $licenseKey = Str::upper(Str::random(16));
        } while ($this->repository->isLicenseExisted($licenseKey));

        return $licenseKey;
    }
}
