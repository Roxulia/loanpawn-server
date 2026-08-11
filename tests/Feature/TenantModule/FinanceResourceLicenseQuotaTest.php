<?php

namespace Tests\Feature\TenantModule;

use App\DataObjects\RequestObjects\StoreCurrencyRequest;
use App\DataObjects\RequestObjects\StoreExchangeRatePairRequest;
use App\Exceptions\TenantAccessDenied;
use App\Models\PlatformModule\Package;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\Tenant;
use App\Models\PlatformModule\TenantLicense;
use App\Services\TenantModule\TenantCurrencyService;
use App\Services\TenantModule\TenantExchangeRatePairService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceResourceLicenseQuotaTest extends TestCase
{
    use RefreshDatabase;

    public function test_currency_create_and_delete_update_the_license_counter(): void
    {
        [$tenant, $license] = $this->tenantWithLicense(maxCurrencies: 2);
        app(TenantContext::class)->set($tenant);
        $service = app(TenantCurrencyService::class);

        $service->create($this->currencyRequest('AAA'));
        $service->create($this->currencyRequest('BBB'));

        $this->assertSame(2, $license->refresh()->current_currency_type_count);

        $service->delete('AAA');

        $this->assertSame(1, $license->refresh()->current_currency_type_count);
    }

    public function test_currency_creation_is_rejected_without_changing_the_counter_at_the_limit(): void
    {
        [$tenant, $license] = $this->tenantWithLicense(maxCurrencies: 1);
        app(TenantContext::class)->set($tenant);
        $service = app(TenantCurrencyService::class);
        $service->create($this->currencyRequest('AAA'));

        try {
            $service->create($this->currencyRequest('BBB'));
            $this->fail('Expected the currency quota to reject creation.');
        } catch (TenantAccessDenied) {
            $this->assertDatabaseMissing('currencies', [
                'tenant_id' => $tenant->id,
                'code' => 'BBB',
            ]);
            $this->assertSame(1, $license->refresh()->current_currency_type_count);
        }
    }

    public function test_exchange_pair_create_and_delete_update_the_license_counter(): void
    {
        [$tenant, $license] = $this->tenantWithLicense(maxCurrencies: 2, maxExchangePairs: 1);
        app(TenantContext::class)->set($tenant);
        $currencies = app(TenantCurrencyService::class);
        $currencies->create($this->currencyRequest('AAA'));
        $currencies->create($this->currencyRequest('BBB'));
        $pairs = app(TenantExchangeRatePairService::class);

        $pairs->create(new StoreExchangeRatePairRequest('AAA', 'BBB', true));
        $this->assertSame(1, $license->refresh()->current_exchange_pair_count);

        $pairs->delete('AAA-BBB');
        $this->assertSame(0, $license->refresh()->current_exchange_pair_count);
    }

    private function tenantWithLicense(int $maxCurrencies, int $maxExchangePairs = 1): array
    {
        $owner = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Finance Quota Owner',
            'email' => 'finance-quota-'.random_int(0, 99999999).'@example.com',
            'password' => 'secret123',
            'status' => 'active',
        ]);
        $tenant = Tenant::query()->create([
            'platform_user_id' => $owner->id,
            'name' => 'Finance Quota Tenant',
            'tenant_code' => 'finance-quota-'.random_int(0, 99999999),
            'status' => 'active',
        ]);
        $packageCode = 'finance-quota-'.random_int(0, 99999999);
        $package = Package::query()->create([
            'code' => $packageCode,
            'name' => 'Finance Quota',
            'price' => 1000,
            'max_currency_type_count' => $maxCurrencies,
            'max_exchange_pair_count' => $maxExchangePairs,
            'is_active' => true,
        ]);
        $license = TenantLicense::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $package->id,
            'license_key' => 'FINQUOTA'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'plan_type' => $packageCode,
            'status' => 'active',
            'expires_at' => now()->addMonth(),
        ]);

        return [$tenant, $license];
    }

    private function currencyRequest(string $code): StoreCurrencyRequest
    {
        return new StoreCurrencyRequest(
            code: $code,
            name: "{$code} Currency",
            symbol: null,
            decimalPrecision: 2,
            roundingMode: 'HALF_UP',
            adjustmentStep: null,
            isActive: true,
        );
    }
}
