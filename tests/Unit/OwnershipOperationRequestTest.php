<?php

namespace Tests\Unit;

use App\DTOs\Ownership\Internal\RequestObjects\OwnershipSaleRequest;
use App\DTOs\Ownership\Internal\RequestObjects\OwnershipPledgeRequest;
use App\DTOs\Ownership\Internal\RequestObjects\OwnershipPledgeSource;
use Tests\TestCase;

/** Internal command contracts; these cases do not prove source eligibility or lender authorization. */
class OwnershipOperationRequestTest extends TestCase
{
    public function test_sale_keeps_exact_quantity_and_distinct_business_references(): void
    {
        // One sale line targets one lot; quantity is decimal text and piece order remains explicit.
        $request = new OwnershipSaleRequest(' OWN-A ', ' LOT-A ', '2', ' LINE-A ', [' B ', ' 0 '], 'sale-key');
        $this->assertSame('OWN-A', $request->ownedItemCode);
        $this->assertSame('LOT-A', $request->lotCode);
        $this->assertSame('LINE-A', $request->saleLineCode);
        $this->assertSame('2.000', $request->quantity);
        $this->assertSame(['B', '0'], $request->serializedItemCodes);
        $this->assertSame('sale-key', $request->idempotencyKey);
        $this->assertFalse(method_exists($request, 'fromValidated'));
    }

    public function test_pledge_retains_typed_context_separately_from_its_source_reference(): void
    {
        // Typed resolver output identifies the expected collateral without asserting that a loan is authorized.
        $source = new OwnershipPledgeSource(1, ' BusinessLoanCollateral ', ' L1 ', ' OWN-A ', ' LOT-A ', '1', ' mmk ', [' 0 ']);
        $request = new OwnershipPledgeRequest(' OWN-A ', ' LOT-A ', '1', ' BusinessLoanCollateral ', ' L1 ', [' 0 '], 'pledge-key', $source);
        $this->assertSame($source, $request->sourceContext);
        $this->assertSame(1, $source->tenantId);
        $this->assertSame('MMK', $source->currencyCode);
        $this->assertSame('OWN-A', $source->ownedItemCode);
        $this->assertSame('LOT-A', $source->lotCode);
        $this->assertSame('BusinessLoanCollateral', $request->sourceType);
        $this->assertSame('L1', $request->sourceCode);
        $this->assertSame('1.000', $request->quantity);
        $this->assertSame(['0'], $request->serializedItemCodes);
        $this->assertSame('pledge-key', $request->idempotencyKey);
        $this->assertFalse(method_exists($request, 'fromValidated'));
        $this->assertFalse(method_exists($source, 'fromValidated'));
    }

    public function test_existing_pledge_operation_can_identify_its_source_without_new_resolver_context(): void
    {
        // Release/forfeiture resolve the persisted commitment later; do not fabricate a new source approval.
        $request = new OwnershipPledgeRequest('OWN-A', 'LOT-A', '1', 'BusinessLoanCollateral', 'L1');
        $this->assertNull($request->sourceContext);
        $this->assertSame([], $request->serializedItemCodes);
        $this->assertNull($request->idempotencyKey);
    }
}
