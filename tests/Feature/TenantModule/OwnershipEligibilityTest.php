<?php

namespace Tests\Feature\TenantModule;

use App\DataObjects\RequestObjects\OwnershipPledgeSource;
use App\Exceptions\{ApiException, InvalidTenantRequest};
use App\Services\TenantModule\{InventoryService, OwnershipService};
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\OwnershipPieceTestCase;

/** OWN-07f: real custody/ownership eligibility; pledge source data is an explicit Part 2 boundary fixture. */
class OwnershipEligibilityTest extends OwnershipPieceTestCase
{
    #[DataProvider('operations')]
    public function test_owned_but_unreceived_goods_are_ineligible(string $operation): void
    {
        // Ownership alone is insufficient: this valid paid QUANTITY source has no receipt or stock.
        // Using nonserialized goods avoids accidentally passing because of a missing-piece count error.
        $goods = $this->goods('QUANTITY', '1.000', '12.34');
        $this->assertIneligible($goods, $operation);
    }

    #[DataProvider('operations')]
    public function test_received_but_unowned_goods_are_ineligible(string $operation): void
    {
        // Fully dispose of the old ownership, then receive another unpaid unit of the same item.
        // Stock is now one while owned is zero; custody cannot stand in for a paid acquisition.
        $goods = $this->linkedPiece();
        $this->sellPiece($goods);
        $unpaid = $this->createPurchase($goods['item'], quantity: '1.000', paid: null);
        $receipt = $this->receivePurchase($unpaid, '1.000', ['UNOWNED-B']);
        $this->assertBalances((int) $goods['lot']->owned_item_id, '0.000');
        $this->assertSame('1.000', app(InventoryService::class)->itemDetail($goods['item']->code)->totalQuantity);
        $this->assertIneligible($goods, $operation, $receipt['inventory']['serialized_item_codes']);
    }

    #[DataProvider('operations')]
    public function test_received_owned_but_unlinked_pieces_are_ineligible(string $operation): void
    {
        // Both owned and physical quantities are one, but the piece has no current lot membership.
        // The eligibility boundary must check the actual linkage, not only two positive item totals.
        $goods = $this->goods('SERIALIZED', '1.000', '12.34');
        $receipt = $this->receivePurchase($goods['purchase'], '1.000', ['UNLINKED-A']);
        $this->assertIneligible($goods, $operation, $receipt['inventory']['serialized_item_codes']);
    }

    #[DataProvider('trackingAndOperations')]
    public function test_other_lot_stock_cannot_supply_unreceived_lot(string $mode, string $operation): void
    {
        // Lot A is received/linked; lot B is owned but awaiting receipt for the same explicit item.
        // Positive item-wide stock must not make lot B eligible or borrow lot A's pieces/cost layer.
        $first = $this->goods($mode, '1.000', '12.34');
        $receipt = $this->receivePurchase($first['purchase'], '1.000', $mode === 'SERIALIZED' ? ['LOT-A'] : []);
        $codes = $receipt['inventory']['serialized_item_codes'];
        $this->linkReceipt($first['lot'], $receipt, '1.000', $codes);
        $purchase = $this->paidPurchase($first['item'], '1.000', '12.34');
        $lot = $this->assertAcquisition($purchase, $this->acquire($purchase));
        $second = ['purchase' => $purchase, 'item' => $first['item'], 'lot' => $lot, 'owned' => $first['owned']];
        $this->assertIneligible($second, $operation, $codes);
    }

    #[DataProvider('operations')]
    public function test_received_linked_piece_can_be_reserved(string $operation): void
    {
        // Positive control: the same source adapter and service boundary must succeed once the
        // tenant owns the exact received/linked piece. Otherwise negative cases could fail for unrelated setup.
        $goods = $this->linkedPiece();
        $perform = $this->operation($goods, $operation, [$goods['piece']]);
        $lots = $this->lotSnapshots();
        $perform();

        $rows = DB::table('ownership_movements')->where('tenant_id', $this->ownershipFixture['tenant']->id)
            ->where('acquisition_lot_id', $goods['lot']->id);
        $this->assertSame('1.000', (string) (clone $rows)->sum('owned_delta'));
        $this->assertSame($operation === 'sale' ? '1.000' : '0.000', (string) (clone $rows)->sum('reserved_delta'));
        $this->assertSame($operation === 'pledge' ? '1.000' : '0.000', (string) (clone $rows)->sum('pledged_delta'));
        $this->assertSame($lots, $this->lotSnapshots());
        $this->assertSame('1.000', app(InventoryService::class)->itemDetail($goods['item']->code)->totalQuantity);
    }

    public static function operations(): array
    {
        // Review the sale and pledge eligibility requirements independently at the same stock boundary.
        return ['sale' => ['sale'], 'pledge' => ['pledge']];
    }

    public static function trackingAndOperations(): array
    {
        // Fungible quantities still require receipt-backed capacity in the selected lot;
        // serialized goods additionally require that lot's exact current piece membership.
        return ['quantity sale' => ['QUANTITY', 'sale'], 'quantity pledge' => ['QUANTITY', 'pledge'],
            'serialized sale' => ['SERIALIZED', 'sale'], 'serialized pledge' => ['SERIALIZED', 'pledge']];
    }

    private function assertIneligible(array $goods, string $operation, array $codes = []): void
    {
        // Prepare caller source data before the snapshot. Only the real mutation attempt is inside
        // the no-write check; the test must not mistake its own source setup for a service side effect.
        $perform = $this->operation($goods, $operation, $codes);
        $before = $this->receiptState();
        try {
            $perform();
            $this->fail('Ownership, custody and source linkage must all qualify before reservation.');
        } catch (ApiException $error) {
            $this->assertInstanceOf(InvalidTenantRequest::class, $error);
            $this->assertSame(422, $error->statusCode());
        }
        $this->assertSame($before, $this->receiptState());
    }

    private function operation(array $goods, string $operation, array $codes): \Closure
    {
        // Sale uses a real confirmed source line. Pledge uses explicit typed resolver-output fixture
        // data because Part 8 collateral creation is deferred; no collateral table or bypass flag is invented.
        $service = app(OwnershipService::class);
        if ($operation === 'sale') {
            $line = $this->saleLine($goods['lot'], $goods['item'], '1.000');
            return static fn () => $service->reserveSale($goods['owned']->code, $goods['lot']->code,
                '1.000', $line->code, serializedItemCodes: $codes);
        }
        $code = 'TEST-COLLATERAL-'.$goods['lot']->code;
        $source = new OwnershipPledgeSource(tenantId: (int) $this->ownershipFixture['tenant']->id,
            sourceType: 'BusinessLoanCollateral', sourceCode: $code, ownedItemCode: $goods['owned']->code,
            lotCode: $goods['lot']->code, quantity: '1.000', currencyCode: $goods['lot']->currency_code,
            serializedItemCodes: $codes);

        return static fn () => $service->reservePledge($goods['owned']->code, $goods['lot']->code,
            '1.000', 'BusinessLoanCollateral', $code, serializedItemCodes: $codes, sourceContext: $source);
    }
}
