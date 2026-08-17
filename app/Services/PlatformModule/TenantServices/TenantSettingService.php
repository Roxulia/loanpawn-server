<?php

namespace App\Services\PlatformModule\TenantServices;

use App\DataObjects\RequestObjects\TenantCurrencySettingsUpdate;
use App\DataObjects\RequestObjects\TenantDefaultUserPasswordUpdate;
use App\DataObjects\RequestObjects\TenantTimezoneUpdate;
use App\DataObjects\RequestObjects\ReportingCurrencyAbortRequest;
use App\DataObjects\ResponseObjects\TenantCurrencySettingsResource;
use App\Exceptions\AlreadyUpdatedException;
use App\Models\CoreModule\TenantSetting;
use App\Repository\TenantSettingRepository;
use App\Services\BaseTenantService;
use App\Services\TenantModule\TenantAccountingDayService;
use App\Services\TenantModule\TenantCurrencyService;
use App\Services\TenantModule\Accounting\ReportingCurrencyRecalculationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class TenantSettingService extends BaseTenantService
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        private TenantSettingRepository $repository,
        private TenantCurrencyService $tenantCurrencyService,
        private TenantAccountingDayService $accountingDayService,
        private ReportingCurrencyRecalculationService $reportingCurrencyRecalculationService,
    ) {}

    public function createDefaultTenantSettings(int $tenantId): void
    {
        $defaultSettings = [
            'default_tenant_user_password' => '12345678',
        ];
        foreach ($defaultSettings as $key => $value) {
            $this->repository->firstOrCreate($tenantId, $key, [
                'value' => $value,
                'category' => 'tenant',
            ]);
        }

        $this->ensureCurrencyPreferencesForTenant($tenantId);
    }

    public function getCurrentTenantCurrencyPreferences(): TenantCurrencySettingsResource
    {
        $setting = $this->ensureCurrencyPreferencesForTenant($this->resolveCurrentTenantId());

        return TenantCurrencySettingsResource::fromModel(
            $setting,
            $this->reportingCurrencyRecalculationService->activeForTenant($setting->tenant_id),
        );
    }

    public function updateCurrentTenantCurrencyPreferences(TenantCurrencySettingsUpdate $request): TenantCurrencySettingsResource
    {
        $tenantId = $this->resolveCurrentTenantId();
        $setting = $this->ensureCurrencyPreferencesForTenant($tenantId);

        if ((int) $setting->update_key !== $request->updateKey) {
            throw new AlreadyUpdatedException('This setting is already updated. Please refresh to see the update.');
        }

        $defaultCurrency = $this->tenantCurrencyService->findActiveVisibleForTenant($tenantId, $request->defaultCurrencyId);
        $reportingCurrency = $this->tenantCurrencyService->findActiveVisibleForTenant($tenantId, $request->reportingCurrencyId);

        $previousReportingCurrencyId = (int) $setting->reporting_currency_id;
        $setting = DB::transaction(function () use ($request, $setting, $defaultCurrency, $reportingCurrency, $tenantId, $previousReportingCurrencyId): TenantSetting {
            $updateData = [
                'default_currency_id' => $defaultCurrency->id,
                'reporting_currency_id' => $reportingCurrency->id,
                'category' => 'finance',
                'update_key' => $setting->update_key + 1,
            ];

            if ($request->hasDefaultFinancialUnit) {
                $updateData['value'] = $request->defaultFinancialUnit;
            }

            $updated = $this->repository->update($setting, $updateData);

            if ($previousReportingCurrencyId !== (int) $reportingCurrency->id) {
                $tenantUserId = Auth::guard('tenantuser')->id() ?? throw new \App\Exceptions\InvalidTenantRequest;
                $this->reportingCurrencyRecalculationService->start(
                    $tenantId,
                    (int) $tenantUserId,
                    $previousReportingCurrencyId,
                    (int) $reportingCurrency->id,
                    $this->accountingDayService->currentBusinessDate(),
                );
            }

            return $updated;
        })->load(['defaultCurrency', 'reportingCurrency']);

        return TenantCurrencySettingsResource::fromModel(
            $setting,
            $this->reportingCurrencyRecalculationService->activeForTenant($tenantId),
        );
    }

    public function abortCurrentReportingCurrencyChange(ReportingCurrencyAbortRequest $request): TenantCurrencySettingsResource
    {
        $tenantId = $this->resolveCurrentTenantId();
        $setting = $this->reportingCurrencyRecalculationService->abort(
            $tenantId,
            $request->recalculationId,
            $request->updateKey,
        );

        return TenantCurrencySettingsResource::fromModel($setting, null);
    }

    public function ensureAllTenantCurrencyPreferences(bool $dryRun = false): array
    {
        $summary = ['tenants_checked' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0];

        foreach ($this->repository->allTenantIds() as $tenantId) {
            $summary['tenants_checked']++;
            $setting = $this->repository->currencyPreferences((int) $tenantId);
            $mmk = $this->tenantCurrencyService->findActiveVisibleByCodeForTenant((int) $tenantId, 'MMK');
            $status = $setting === null
                ? 'created'
                : ($setting->default_currency_id === null || $setting->reporting_currency_id === null ? 'updated' : 'unchanged');
            $summary[$status]++;

            if (! $dryRun && $status !== 'unchanged') {
                $this->ensureCurrencyPreferencesForTenant((int) $tenantId);
            }
        }

        return $summary;
    }

    private function ensureCurrencyPreferencesForTenant(int $tenantId): TenantSetting
    {
        $setting = $this->repository->currencyPreferences($tenantId);
        $mmk = $this->tenantCurrencyService->findActiveVisibleByCodeForTenant($tenantId, 'MMK');

        if ($setting?->default_currency_id !== null && $setting->reporting_currency_id !== null) {
            return $setting;
        }

        if ($setting === null) {
            $setting = $this->repository->firstOrCreate($tenantId, 'currency_preferences', [
                'category' => 'finance',
                'default_currency_id' => $setting->default_currency_id ?? $mmk->id,
                'reporting_currency_id' => $mmk->id,
            ]);
        } else {
            $setting = $this->repository->update($setting, [
                'default_currency_id' => $mmk->id,
                'reporting_currency_id' => $setting->reporting_currency_id ?? $mmk->id,
                'category' => 'finance',
                'update_key' => $setting->update_key + 1,
            ]);
        }

        return $setting->load(['defaultCurrency', 'reportingCurrency']);
    }

    public function getTenantDefaultUserPassword(int $tenantId): ?string
    {
        $setting = $this->repository->findByTenantIdAndKey($tenantId, 'default_tenant_user_password');

        return $setting?->value !== null ? (string) $setting->value : null;
    }

    public function getCurrentTenantDefaultUserPassword(): string
    {
        return $this->getTenantDefaultUserPassword($this->resolveCurrentTenantId()) ?? '12345678';
    }

    public function getCurrentTenantTimezone(): TenantSetting
    {
        return $this->getSetting($this->resolveCurrentTenantId(), 'timezone');
    }

    public function updateCurrentTenantTimezone(TenantTimezoneUpdate $request): TenantSetting
    {
        $this->accountingDayService->assertTimezoneChangeAllowed();
        $setting = $this->getCurrentTenantTimezone();
        if ((int) $setting->update_key !== $request->updateKey) {
            throw new AlreadyUpdatedException('This setting is already updated. Please refresh to see the update.');
        }

        return $this->repository->update($setting, [
            'value' => $request->timezone,
            'category' => 'tenant',
            'update_key' => $setting->update_key + 1,
        ]);
    }

    public function updateCurrentTenantDefaultUserPassword(TenantDefaultUserPasswordUpdate $request): TenantSetting
    {
        $setting = $this->getSetting($this->resolveCurrentTenantId(), 'default_tenant_user_password');

        if ((int) $setting->update_key !== $request->updateKey) {
            throw new AlreadyUpdatedException('This setting is already updated. Please refresh to see the update.');
        }

        return $this->repository->update($setting, [
            'value' => $request->defaultTenantUserPassword,
            'category' => 'tenant',
            'update_key' => $setting->update_key + 1,
        ]);
    }

    public function updateTenantDefaultUserPassword(int $tenantId, string $newPassword, int $updateKey): TenantSetting
    {
        $setting = $this->getSetting($tenantId, 'default_tenant_user_password');

        if ((int) $setting->update_key !== $updateKey) {
            throw new AlreadyUpdatedException('This setting is already updated. Please refresh to see the update.');
        }

        return $this->repository->update($setting, [
            'value' => $newPassword,
            'category' => 'tenant',
            'update_key' => $setting->update_key + 1,
        ]);
    }

    private function getSetting(int $tenantId, string $code): TenantSetting
    {
        return $this->repository->firstOrCreate(
            $tenantId,
            $code,
            [
                'value' => match ($code) {
                    'default_tenant_user_password' => '12345678',
                    'timezone' => 'Asia/Yangon',
                    default => null,
                },
                'category' => 'tenant',
            ],
        );
    }
}
