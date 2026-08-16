<?php

namespace Tests\Unit;

use App\DataObjects\RequestObjects\TenantCurrencySettingsUpdate;
use App\Models\CoreModule\Currency;
use App\Models\CoreModule\TenantSetting;
use App\Repository\TenantSettingRepository;
use App\Services\PlatformModule\TenantServices\TenantSettingService;
use App\Services\TenantModule\Accounting\ReportingCurrencyRecalculationService;
use App\Services\TenantModule\TenantAccountingDayService;
use App\Services\TenantModule\TenantCurrencyService;
use App\Support\TenantContext;
use Mockery;
use Tests\TestCase;

class TenantCurrencySettingsServiceTest extends TestCase
{
    public function test_unit_only_update_does_not_start_reporting_currency_recalculation(): void
    {
        app(TenantContext::class)->set(10);
        $currency = $this->currency();
        $setting = Mockery::mock(TenantSetting::class)->makePartial();
        $setting->forceFill([
            'id' => 2,
            'tenant_id' => 10,
            'default_currency_id' => 1,
            'reporting_currency_id' => 1,
            'value' => null,
            'update_key' => 4,
        ]);
        $setting->setRelation('defaultCurrency', $currency);
        $setting->setRelation('reportingCurrency', $currency);
        $setting->shouldReceive('load')->once()->with(['defaultCurrency', 'reportingCurrency'])->andReturnSelf();

        $repository = Mockery::mock(TenantSettingRepository::class);
        $repository->shouldReceive('currencyPreferences')->once()->with(10)->andReturn($setting);
        $repository->shouldReceive('update')->once()->with($setting, Mockery::on(
            static fn (array $data): bool => $data['value'] === 'LAKH'
                && $data['reporting_currency_id'] === 1
                && $data['update_key'] === 5
        ))->andReturnUsing(function (TenantSetting $model, array $data): TenantSetting {
            $model->forceFill($data);

            return $model;
        });

        $currencyService = Mockery::mock(TenantCurrencyService::class);
        $currencyService->shouldReceive('findActiveVisibleByCodeForTenant')->once()->with(10, 'MMK')->andReturn($currency);
        $currencyService->shouldReceive('findActiveVisibleForTenant')->twice()->with(10, 1)->andReturn($currency);
        $accountingDayService = Mockery::mock(TenantAccountingDayService::class);
        $accountingDayService->shouldNotReceive('currentBusinessDate');
        $recalculationService = Mockery::mock(ReportingCurrencyRecalculationService::class);
        $recalculationService->shouldNotReceive('start');
        $recalculationService->shouldReceive('activeForTenant')->once()->with(10)->andReturnNull();

        $service = new TenantSettingService(
            $repository,
            $currencyService,
            $accountingDayService,
            $recalculationService,
        );
        $response = $service->updateCurrentTenantCurrencyPreferences(new TenantCurrencySettingsUpdate(
            defaultCurrencyId: 1,
            reportingCurrencyId: 1,
            updateKey: 4,
            defaultFinancialUnit: 'LAKH',
            hasDefaultFinancialUnit: true,
        ));

        $this->assertSame('LAKH', $response->defaultFinancialUnit);
        $this->assertSame(5, $response->updateKey);
    }

    private function currency(): Currency
    {
        return (new Currency)->forceFill([
            'id' => 1,
            'code' => 'MMK',
            'name' => 'Myanmar Kyat',
            'symbol' => 'K',
            'decimal_precision' => 0,
            'rounding_mode' => 'HALF_UP',
            'is_default' => true,
            'is_active' => true,
            'update_key' => 1,
        ]);
    }
}
