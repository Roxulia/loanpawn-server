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
use App\Models\PlatformModule\Package;
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

    public function findPlan(int $planId): Package
    {
        return $this->packageService->findActiveById($planId);
    }

    public function findPlanByCode(string $code): Package
    {
        return $this->packageService->findActiveByCode($code);
    }

    public function trialPlanForCategory(int $categoryId): Package
    {
        return $this->packageService->trialForCategory($categoryId);
    }

    public function defaultTrialPlan(): Package
    {
        return $this->packageService->findActiveByCode('trial');
    }

    public function getTenantLicense(int $tenantId): TenantLicense
    {
        $license = $this->repository->findByTenantId($tenantId);

        if (! $license) {
            throw new TenantNotFound('Tenant license not found.');
        }

        if ($license->plan_id === null && $license->plan_type !== '') {
            $plan = $this->packageService->findByCode($license->plan_type);
            $license->forceFill(['plan_id' => $plan->id])->saveQuietly();
            $license->setRelation('plan', $plan);
        }

        return $license;
    }

    public function getTenantLicenseForUpdate(int $tenantId): TenantLicense
    {
        $license = $this->repository->findByTenantIdForUpdate($tenantId);

        if (! $license) {
            throw new TenantNotFound('Tenant license not found.');
        }

        return $license->loadMissing('plan');
    }

    public function updateLicense(TenantLicense $license, array $data): TenantLicense
    {
        return $this->repository->update($license, $data);
    }

    public function createPlanTransition(array $data): void
    {
        $this->repository->createPlanTransition($data);
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

        return $this->packageService->planHasFeature($license->plan?->code ?? $license->plan_type, $featureCode);
    }

    public function ensureTenantCanOpenApp(int $tenantId): TenantLicense
    {
        $license = $this->getTenantLicense($tenantId);

        if ($license->status === 'expired' || ($license->expires_at !== null && $license->expires_at->lte(now()))) {
            throw new InvalidTenantRequest('Tenant is expired');
        }

        return $license;
    }

    public function ensureCurrentTenantHasFeature(string $featureCode): TenantLicense
    {
        return $this->ensureTenantHasFeature($this->resolveCurrentTenantId(), $featureCode);
    }

    public function ensureCurrentTenantHasAnyFeature(array $featureCodes): TenantLicense
    {
        $tenantId = $this->resolveCurrentTenantId();
        $license = $this->getTenantLicense($tenantId);
        $planCode = $license->plan?->code ?? $license->plan_type;

        foreach (array_unique(array_filter($featureCodes)) as $featureCode) {
            if ($this->packageService->planHasFeature($planCode, $featureCode)) {
                return $license;
            }
        }

        throw new FeatureNotAvailableForPlan;
    }

    public function ensureTenantHasFeature(int $tenantId, string $featureCode): TenantLicense
    {
        $license = $this->getTenantLicense($tenantId);

        if (! $this->packageService->planHasFeature($license->plan?->code ?? $license->plan_type, $featureCode)) {
            throw new FeatureNotAvailableForPlan;
        }

        return $license;
    }

    public function getCurrentTenantFeatureValue(string $featureCode): ?string
    {
        $license = $this->getCurrentTenantLicense();

        return $this->packageService->featureValue($license->plan?->code ?? $license->plan_type, $featureCode);
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
            : $issuedAt->copy()->addMonths(4);
        $license = TenantLicense::query()->create([
            'tenant_id' => $tenantId,
            'plan_id' => $request->planId,
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
        return $this->repository->createStatusLog($data);
    }

    public function applyApprovedTenantRequest(TenantRequest $tenantRequest, int $approvedBy, ?string $adminReviewNote = null): TenantLicense
    {
        $license = $this->getTenantLicense((int) $tenantRequest->tenant_id);
        if ($tenantRequest->requested_plan_id === null && $tenantRequest->requested_plan_type) {
            $requestedPlan = $this->packageService->findByCode($tenantRequest->requested_plan_type);
            $tenantRequest->forceFill([
                'requested_plan_id' => $requestedPlan->id,
                'requested_category_id' => $requestedPlan->category_id,
            ])->saveQuietly();
            $tenantRequest->setRelation('requestedPlan', $requestedPlan);
        }
        $oldStatus = $license->status;
        $data = [
            'status' => 'active',
            'approved_by' => $approvedBy,
            'notes' => $adminReviewNote ?? $license->notes,
            'update_key' => $license->update_key + 1,
        ];

        $resetLicenseTerm = (bool) ($tenantRequest->business_info['reset_license_term'] ?? false);

        if ($resetLicenseTerm && $tenantRequest->request_type === 'plan_change') {
            $data['plan_id'] = $tenantRequest->requested_plan_id;
            $data['plan_type'] = $tenantRequest->requestedPlan?->code ?? $tenantRequest->requested_plan_type;
            $data['starts_at'] = now();
            $data['activated_at'] = now();
            $data['expires_at'] = now()->addMonths((int) $tenantRequest->extension_months);
            if ($tenantRequest->requested_category_id !== null) {
                $tenantRequest->tenant()->update(['category_id' => $tenantRequest->requested_category_id]);
            }
        } elseif ($tenantRequest->request_type === 'extension') {
            $baseDate = $license->expires_at ?? now();
            $data['expires_at'] = $baseDate->copy()->addMonths((int) $tenantRequest->extension_months);
        }

        $targetPlan = $tenantRequest->requestedPlan;
        $isDowngrade = $targetPlan !== null
            && $license->plan !== null
            && $targetPlan->rank < $license->plan->rank;

        if (! $resetLicenseTerm && $tenantRequest->request_type === 'plan_change' && $isDowngrade) {
            $this->scheduleDowngrade($license, $tenantRequest, $approvedBy);
        } elseif (! $resetLicenseTerm && $tenantRequest->request_type === 'plan_change') {
            $data['plan_id'] = $tenantRequest->requested_plan_id;
            $data['plan_type'] = $tenantRequest->requested_plan_type;
            if ($tenantRequest->requested_category_id !== null) {
                $tenantRequest->tenant()->update(['category_id' => $tenantRequest->requested_category_id]);
            }
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

    public function resetCurrentMonthSlipCounts(): int
    {
        return $this->runLoggedOperation(__METHOD__, function (): int {
            return $this->repository->resetCurrentMonthSlipCounts();
        });
    }

    public function incrementCurrentMonthSlipCount(?int $tenantId = null): void
    {
        $license = $tenantId === null
            ? $this->repository->findByTenantId($this->resolveCurrentTenantId())
            : $this->repository->findByTenantId($tenantId);

        if ($license === null) {
            return;
        }

        $license->update(
            [
                'current_month_slip_count' => $license->current_month_slip_count + 1,
            ]
        );
    }

    public function incrementStaffCount(?int $tenantId = null): void
    {
        $license = $tenantId === null
            ? $this->repository->findByTenantId($this->resolveCurrentTenantId())
            : $this->repository->findByTenantId($tenantId);

        if ($license === null) {
            return;
        }

        $license->update(
            [
                'current_staff_count' => $license->current_staff_count + 1,
            ]
        );
    }

    public function decrementCurrentMonthSlipCount(?int $tenantId = null): void
    {
        $license = $tenantId === null
            ? $this->repository->findByTenantId($this->resolveCurrentTenantId())
            : $this->repository->findByTenantId($tenantId);

        if ($license === null) {
            return;
        }

        $license->update(
            [
                'current_month_slip_count' => $license->current_month_slip_count - 1,
            ]
        );
    }

    public function decrementStaffCount(?int $tenantId = null): void
    {
        $license = $tenantId === null
            ? $this->repository->findByTenantId($this->resolveCurrentTenantId())
            : $this->repository->findByTenantId($tenantId);

        if ($license === null) {
            return;
        }

        $license->update(
            [
                'current_staff_count' => $license->current_staff_count - 1,
            ]
        );
    }

    public function incrementAccountCount(?int $tenantId = null): void
    {
        $this->incrementCounter('current_account_count', $tenantId);
    }

    public function decrementAccountCount(?int $tenantId = null): void
    {
        $this->decrementCounter('current_account_count', $tenantId);
    }

    public function incrementCurrencyTypeCount(?int $tenantId = null): void
    {
        $this->incrementCounter('current_currency_type_count', $tenantId);
    }

    public function decrementCurrencyTypeCount(?int $tenantId = null): void
    {
        $this->decrementCounter('current_currency_type_count', $tenantId);
    }

    public function incrementExchangePairCount(?int $tenantId = null): void
    {
        $this->incrementCounter('current_exchange_pair_count', $tenantId);
    }

    public function decrementExchangePairCount(?int $tenantId = null): void
    {
        $this->decrementCounter('current_exchange_pair_count', $tenantId);
    }

    public function checkIfLimitReach(string $attribute, ?int $tenantId = null, bool $lockForUpdate = false): bool
    {
        $packageAttribute = match ($attribute) {
            'current_month_slip_count' => 'max_slip_per_month',
            'current_staff_count' => 'max_staff_count',
            'current_account_count' => 'max_account_count',
            'current_currency_type_count' => 'max_currency_type_count',
            'current_exchange_pair_count' => 'max_exchange_pair_count',
            default => throw new InvalidTenantRequest('Unsupported license limit attribute.'),
        };

        $resolvedTenantId = $tenantId ?? $this->resolveCurrentTenantId();
        $license = $lockForUpdate
            ? $this->repository->findByTenantIdForUpdate($resolvedTenantId)
            : $this->repository->findByTenantId($resolvedTenantId);

        if ($license === null) {
            return false;
        }

        $package = $license->plan ?? $this->packageService->findByCode($license->plan_type);
        $maxAllowed = $package->{$packageAttribute};

        if ($maxAllowed === null) {
            return false;
        }

        return ((int) $license->{$attribute} + 1) > (int) $maxAllowed;
    }

    private function incrementCounter(string $attribute, ?int $tenantId = null): void
    {
        $this->repository->incrementCounter($tenantId ?? $this->resolveCurrentTenantId(), $attribute);
    }

    private function decrementCounter(string $attribute, ?int $tenantId = null): void
    {
        $this->repository->decrementCounter($tenantId ?? $this->resolveCurrentTenantId(), $attribute);
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
            'from_plan_id' => $license->plan_id,
            'to_plan_id' => $tenantRequest->requested_plan_id,
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

                Mail::to($ownerEmail)->send(
                    (new TenantLicenseExpiringMail($license, $billingUrl))
                        ->locale($this->mailLocaleFor($license->tenant?->owner?->prefer_lang))
                );
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

    protected function mailLocaleFor(?string $locale): string
    {
        return in_array($locale, config('app.supported_locales', []), true)
            ? $locale
            : config('app.locale');
    }
}
