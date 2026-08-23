<?php

namespace Tests\Unit;

use App\DataObjects\BaseDataObject;
use App\DataObjects\RequestObjects\StoreCurrencyRequest;
use App\DataObjects\RequestObjects\StoreExchangeRateRequest;
use App\DataObjects\RequestObjects\StoreFinancialAccountRequest;
use App\DataObjects\RequestObjects\TenantCurrencySettingsUpdate;
use App\DataObjects\ResponseObjects\CurrencyResource;
use App\DataObjects\ResponseObjects\DefaultDataListPage;
use App\DataObjects\ResponseObjects\ExchangeRateEntryResource;
use App\DataObjects\ResponseObjects\ExchangeRatePairResource;
use App\DataObjects\ResponseObjects\TenantCurrencySettingsResource;
use App\Http\Controllers\TenantModule\TenantCurrencyController;
use App\Models\CoreModule\Currency;
use App\Models\CoreModule\ExchangeRateEntry;
use App\Models\CoreModule\ExchangeRatePair;
use App\Models\CoreModule\TenantSetting;
use App\Services\TenantModule\TenantCurrencyService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;
use Illuminate\Support\Facades\Validator;

class FinanceDataObjectsTest extends TestCase
{
    public function test_financial_account_unit_validation_is_supported_and_amount_dependent(): void
    {
        $withBalance = Validator::make([
            'account_type' => 'cash',
            'currency_type' => 'MMK',
            'account_name' => 'Main Cash',
            'balance' => 10,
            'balance_unit' => 'THOUSAND',
        ], StoreFinancialAccountRequest::rules())->validate();
        $withoutBalance = Validator::make([
            'account_type' => 'cash',
            'currency_type' => 'MMK',
            'account_name' => 'Main Cash',
            'balance_unit' => 'THOUSAND',
        ], StoreFinancialAccountRequest::rules())->validate();

        $this->assertSame('THOUSAND', $withBalance['balance_unit']);
        $this->assertArrayNotHasKey('balance_unit', $withoutBalance);
    }

    public function test_request_data_objects_expose_typed_snake_case_payloads(): void
    {
        $currencyRequest = StoreCurrencyRequest::fromValidated([
            'code' => 'USD',
            'name' => 'US Dollar',
            'symbol' => '$',
        ]);
        $exchangeRateRequest = StoreExchangeRateRequest::fromValidated([
            'pair_code' => 'USD-MMK',
            'buying_rate' => '3500.125',
            'selling_rate' => '3520.125',
        ]);

        $this->assertInstanceOf(BaseDataObject::class, $currencyRequest);
        $this->assertSame('USD', $currencyRequest->code);
        $this->assertNull($currencyRequest->isActive);
        $this->assertSame('USD-MMK', $exchangeRateRequest->pairCode);
        $this->assertSame([
            'pair_code' => 'USD-MMK',
            'buying_rate' => '3500.125',
            'selling_rate' => '3520.125',
            'idempotency_key' => null,
            'effective_date' => null,
        ], $exchangeRateRequest->toArray());
    }

    public function test_response_data_objects_use_inherited_serializer(): void
    {
        foreach ([CurrencyResource::class, ExchangeRatePairResource::class, ExchangeRateEntryResource::class] as $responseClass) {
            $this->assertSame(
                BaseDataObject::class,
                (new ReflectionMethod($responseClass, 'toArray'))->getDeclaringClass()->getName()
            );
        }
    }

    public function test_exchange_rate_response_shape_is_preserved(): void
    {
        $baseCurrency = $this->currency(1, 'USD', 'US Dollar', null);
        $quoteCurrency = $this->currency(2, 'MMK', 'Myanmar Kyat', 10);
        $pair = (new ExchangeRatePair)->forceFill([
            'id' => 3,
            'tenant_id' => 10,
            'code' => 'USD-MMK',
            'is_default' => false,
            'is_active' => true,
            'update_key' => 4,
        ]);
        $pair->setRelation('baseCurrency', $baseCurrency);
        $pair->setRelation('quoteCurrency', $quoteCurrency);

        $entry = (new ExchangeRateEntry)->forceFill([
            'id' => 5,
            'tenant_id' => 10,
            'code' => 'RATE-1',
            'buying_rate' => '3500.000000000000',
            'selling_rate' => '3520.000000000000',
            'effective_date' => CarbonImmutable::parse('2026-08-10'),
            'observed_at' => CarbonImmutable::parse('2026-08-10 09:00:00'),
            'source' => 'TENANT',
            'is_void' => false,
        ]);
        $entry->setRelation('pair', $pair);

        $response = ExchangeRateEntryResource::fromModel($entry)->toArray();

        $this->assertSame('USD/MMK', $response['pair']['display_code']);
        $this->assertSame('USD', $response['pair']['base_currency']['code']);
        $this->assertSame('3500.000000000000', $response['buying_rate']);
        $this->assertSame('3520.000000000000', $response['selling_rate']);
        $this->assertTrue($response['can_correct']);
        $this->assertTrue($response['can_void']);
    }

    public function test_default_data_list_page_accepts_transformed_array_items(): void
    {
        $paginator = new LengthAwarePaginator(
            items: [['id' => 1, 'display_code' => 'USD/MMK']],
            total: 1,
            perPage: 50,
            currentPage: 1,
        );

        $response = DefaultDataListPage::fromPaginator($paginator)->toArray();

        $this->assertSame([['id' => 1, 'display_code' => 'USD/MMK']], $response['items']);
        $this->assertSame(1, $response['total']);
    }

    public function test_tenant_currency_list_uses_currency_resources_for_action_flags(): void
    {
        $currency = $this->currency(1, 'USD', 'US Dollar', 10);
        $paginator = new LengthAwarePaginator([$currency], 1, 100, 1);
        $service = Mockery::mock(TenantCurrencyService::class);
        $service->shouldReceive('list')->once()->with(100)->andReturn($paginator);

        $response = (new TenantCurrencyController($service))->index(
            Request::create('/tenant/currencies', 'GET', ['per_page' => 100])
        )->getData(true);
        $item = $response['data']['items'][0];

        $this->assertSame('TENANT', $item['source']);
        $this->assertTrue($item['can_update']);
        $this->assertTrue($item['can_delete']);
    }

    public function test_default_financial_unit_is_optional_and_can_be_cleared(): void
    {
        $legacyRequest = TenantCurrencySettingsUpdate::fromValidated([
            'default_currency_id' => 1,
            'reporting_currency_id' => 2,
            'update_key' => 3,
        ]);
        $fixedUnitRequest = TenantCurrencySettingsUpdate::fromValidated([
            'default_currency_id' => 1,
            'reporting_currency_id' => 2,
            'default_financial_unit' => 'LAKH',
            'update_key' => 3,
        ]);
        $autoRequest = TenantCurrencySettingsUpdate::fromValidated([
            'default_currency_id' => 1,
            'reporting_currency_id' => 2,
            'default_financial_unit' => null,
            'update_key' => 3,
        ]);

        $this->assertFalse($legacyRequest->hasDefaultFinancialUnit);
        $this->assertTrue($fixedUnitRequest->hasDefaultFinancialUnit);
        $this->assertSame('LAKH', $fixedUnitRequest->defaultFinancialUnit);
        $this->assertTrue($autoRequest->hasDefaultFinancialUnit);
        $this->assertNull($autoRequest->defaultFinancialUnit);
        $this->assertTrue(Validator::make([
            'default_currency_id' => 1,
            'reporting_currency_id' => 2,
            'default_financial_unit' => 'NOT_A_UNIT',
            'update_key' => 3,
        ], TenantCurrencySettingsUpdate::rules())->fails());
    }

    public function test_currency_settings_response_includes_default_financial_unit(): void
    {
        $currency = $this->currency(1, 'MMK', 'Myanmar Kyat', null);
        $setting = (new TenantSetting)->forceFill([
            'id' => 2,
            'tenant_id' => 10,
            'default_currency_id' => 1,
            'reporting_currency_id' => 1,
            'value' => 'MILLION',
            'update_key' => 4,
        ]);
        $setting->setRelation('defaultCurrency', $currency);
        $setting->setRelation('reportingCurrency', $currency);

        $response = TenantCurrencySettingsResource::fromModel($setting)->toArray();

        $this->assertSame('MILLION', $response['default_financial_unit']);
    }

    private function currency(int $id, string $code, string $name, ?int $tenantId): Currency
    {
        return (new Currency)->forceFill([
            'id' => $id,
            'tenant_id' => $tenantId,
            'code' => $code,
            'name' => $name,
            'symbol' => null,
            'decimal_precision' => 2,
            'rounding_mode' => 'HALF_UP',
            'adjustment_step' => null,
            'is_default' => $tenantId === null,
            'is_active' => true,
            'update_key' => 1,
        ]);
    }
}
