<?php

namespace Tests\Unit;

use App\DataObjects\BaseDataObject;
use App\DataObjects\RequestObjects\StoreCurrencyRequest;
use App\DataObjects\RequestObjects\StoreExchangeRateRequest;
use App\DataObjects\ResponseObjects\CurrencyResource;
use App\DataObjects\ResponseObjects\DefaultDataListPage;
use App\DataObjects\ResponseObjects\ExchangeRateEntryResource;
use App\DataObjects\ResponseObjects\ExchangeRatePairResource;
use App\Models\CoreModule\Currency;
use App\Models\CoreModule\ExchangeRateEntry;
use App\Models\CoreModule\ExchangeRatePair;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use ReflectionMethod;
use Tests\TestCase;

class FinanceDataObjectsTest extends TestCase
{
    public function test_request_data_objects_expose_typed_snake_case_payloads(): void
    {
        $currencyRequest = StoreCurrencyRequest::fromValidated([
            'code' => 'USD',
            'name' => 'US Dollar',
            'symbol' => '$',
            'decimal_precision' => 2,
            'rounding_mode' => 'HALF_UP',
        ]);
        $exchangeRateRequest = StoreExchangeRateRequest::fromValidated([
            'pair_code' => 'USD-MMK',
            'buying_rate' => '3500.125',
            'selling_rate' => '3520.125',
        ]);

        $this->assertInstanceOf(BaseDataObject::class, $currencyRequest);
        $this->assertSame('USD', $currencyRequest->code);
        $this->assertSame(2, $currencyRequest->decimalPrecision);
        $this->assertNull($currencyRequest->isActive);
        $this->assertSame('USD-MMK', $exchangeRateRequest->pairCode);
        $this->assertSame([
            'pair_code' => 'USD-MMK',
            'buying_rate' => '3500.125',
            'selling_rate' => '3520.125',
            'idempotency_key' => null,
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
