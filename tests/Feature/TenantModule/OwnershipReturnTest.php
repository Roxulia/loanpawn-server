<?php

namespace Tests\Feature\TenantModule;

use App\Models\PurchasingModule\{PurchaseReturn, PurchaseReturnLine};
use App\Services\TenantModule\OwnershipService;
use Illuminate\Support\Facades\DB;
use Tests\Support\OwnershipQuantityTestCase;

class OwnershipReturnTest extends OwnershipQuantityTestCase
{
    public function test_immediate_reduction_cannot_bypass_existing_reservation(): void
    {
        // Existing Sales callers already reserve first. They must consume that source commitment;
        // a direct reduction using an allocation code must not leave its reservation stranded.
        $goods = $this->linkedGoods();
        $line = $this->saleLine($goods['lot'], $goods['item'], '10.000');
        $allocation = $this->allocation($goods['lot'], $line);
        $this->change($goods, 'reserve', '10.000', $line->code);
        $this->assertNoChange(fn () => app(OwnershipService::class)->reduceForSale($goods['owned']->code,
            $goods['lot']->code, '1.000', 'SaleLine', $allocation->code, 'no-bypass'));
    }

    public function test_purchase_return_reduces_only_free_original_lot(): void
    {
        // This is the internal Ownership half of a return, not supplier credits/refunds or stock issue.
        $goods = $this->goods();
        $receipt = $this->receivePurchase($goods['purchase'], '10.000');
        $this->linkReceipt($goods['lot'], $receipt, '10.000');
        $line = $this->purchaseReturn($goods, $receipt, '2.000');
        $lots = $this->lotSnapshots();
        // Plenty of free lot quantity exists, so 2.001 specifically exceeds this return source's two.
        $this->assertNoChange(fn () => app(OwnershipService::class)->reduceForPurchaseReturn(
            $goods['owned']->code, $goods['lot']->code, '2.001', $line->code, 'excess-return'));
        app(OwnershipService::class)->reduceForPurchaseReturn($goods['owned']->code, $goods['lot']->code,
            '2.000', $line->code, 'purchase-return');
        $this->assertLotBalance($goods, '8.000');
        $this->assertSame('8.000', $this->lotData($goods)['received_backed_quantity']);
        $this->assertSame($lots, $this->lotSnapshots());
        $this->assertNoChange(fn () => app(OwnershipService::class)->reduceForPurchaseReturn(
            $goods['owned']->code, $goods['lot']->code, '2.000', $line->code, 'repeated-return'));
    }

    public function test_purchase_return_cannot_use_reserved_or_other_source_goods(): void
    {
        $goods = $this->goods();
        $receipt = $this->receivePurchase($goods['purchase'], '10.000');
        $this->linkReceipt($goods['lot'], $receipt, '10.000');
        $line = $this->purchaseReturn($goods, $receipt, '2.000');
        $this->change($goods, 'reserve', '9.000', $this->sale($goods));
        $this->assertNoChange(fn () => app(OwnershipService::class)->reduceForPurchaseReturn(
            $goods['owned']->code, $goods['lot']->code, '2.000', $line->code, 'reserved-return'));
        $other = $this->linkedGoods();
        $this->assertNoChange(fn () => app(OwnershipService::class)->reduceForPurchaseReturn(
            $other['owned']->code, $other['lot']->code, '1.000', $line->code, 'wrong-return'));
    }

    public function test_serialized_purchase_return_reuses_validated_memberships(): void
    {
        // Validation resolves exact pieces once. The backing mutation must use that same selection.
        $goods = $this->linkedPiece('RETURN-REUSE');
        $source = $this->purchaseReturn($goods, $goods['receipt'], '1.000', [$goods['piece']]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            app(OwnershipService::class)->reduceForPurchaseReturn($goods['owned']->code, $goods['lot']->code,
                '1.000', $source->code, 'return-reused-selection');
            $membershipReads = collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) =>
                str_starts_with(strtolower($sql), 'select') && str_contains($sql, 'ownership_piece_memberships'));
        } finally {
            DB::disableQueryLog();
        }
        $this->assertCount(1, $membershipReads);
        $this->assertCurrentPieces($goods, []);
        $this->assertLotBalance($goods, '0.000');
    }

    private function purchaseReturn(array $goods, array $receipt, string $quantity, array $pieceCodes = []): PurchaseReturnLine
    {
        // The stored receipt reference supplies source authority; no paid flag or alternate lot is trusted.
        $tenant = $this->ownershipFixture['tenant']->id;
        $return = PurchaseReturn::query()->create(['tenant_id' => $tenant, 'code' => 'PR'.bin2hex(random_bytes(5)),
            'purchase_order_id' => $goods['purchase']['order']->id, 'returned_at' => '2026-10-15',
            'created_by' => $this->ownershipFixture['actor']->id]);
        return PurchaseReturnLine::query()->create(['tenant_id' => $tenant, 'code' => 'PL'.bin2hex(random_bytes(5)),
            'purchase_return_id' => $return->id, 'purchase_receipt_line_id' => $receipt['receiptLine']->id,
            'returned_quantity' => $quantity, 'serialized_item_codes' => $pieceCodes, 'supplier_credit_amount' => '0.00',
            'supplier_payable_adjusted_amount' => '0.00', 'cash_refund_amount' => '0.00']);
    }
}
