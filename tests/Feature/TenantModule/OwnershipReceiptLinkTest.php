<?php

namespace Tests\Feature\TenantModule;

use App\Models\OwnershipModule\OwnershipReceiptLink;
use App\Services\TenantModule\{InventoryService, OwnershipService};
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\OwnershipReceiptTestCase;

class OwnershipReceiptLinkTest extends OwnershipReceiptTestCase
{
    public function test_partial_receipt_links_without_another_acquisition(): void
    {
        // Pay for 10 units before receipt. Receiving 4 and linking those 4 must not make owned=14
        // or change the purchase cost/date/currency; six units remain genuinely awaiting arrival.
        $goods = $this->goods();
        $snapshot = $this->lotSnapshots();
        $receipt = $this->receivePurchase($goods['purchase'], '4.000');
        $this->linkReceipt($goods['lot'], $receipt, '4.000');

        $this->assertOriginalAcquisition($goods, $snapshot);
        $data = $this->lotData($goods);
        $this->assertSame('4.000', $data['received_backed_quantity']);
        $this->assertSame('6.000', $data['awaiting_receipt_quantity']);
        $this->assertSame('0.000', $data['pending_link_quantity']);
        $this->assertSame('4.000', app(InventoryService::class)->itemDetail($goods['item']->code)->totalQuantity);
        $link = OwnershipReceiptLink::query()->where('acquisition_lot_id', $goods['lot']->id)->sole();
        $this->assertSame((int) $receipt['receiptLine']->id, (int) $link->purchase_receipt_line_id);
        $this->assertSame('4.000', $link->linked_quantity);
        $this->assertSame('4.000', $link->remaining_owned_quantity);
    }

    public function test_all_received_goods_can_be_linked_in_distinct_parts(): void
    {
        // All 10 units have already arrived, so awaiting-receipt is zero before linking starts.
        // Capacity must use received-but-unlinked quantity, not incorrectly reject because nothing is awaiting.
        $goods = $this->goods();
        $snapshot = $this->lotSnapshots();
        $receipt = $this->receivePurchase($goods['purchase'], '10.000');
        $this->linkReceipt($goods['lot'], $receipt, '4.000', key: 'first-part');
        $this->linkReceipt($goods['lot'], $receipt, '6.000', key: 'last-part');

        $this->assertOriginalAcquisition($goods, $snapshot);
        $data = $this->lotData($goods);
        $this->assertSame('10.000', $data['received_backed_quantity']);
        $this->assertSame('0.000', $data['awaiting_receipt_quantity']);
        $this->assertSame('0.000', $data['pending_link_quantity']);
        $this->assertSame('10.000', (string) OwnershipReceiptLink::query()
            ->where('acquisition_lot_id', $goods['lot']->id)->sum('linked_quantity'));
    }

    public function test_distinct_receipts_link_to_one_original_purchase_lot(): void
    {
        // Internal partial receipts are a foundation case, not activation of staged purchase UI.
        // Each receipt retains its own provenance while both resolve to the same purchase-line lot.
        $goods = $this->goods();
        $snapshot = $this->lotSnapshots();
        $first = $this->receivePurchase($goods['purchase'], '4.000');
        $this->linkReceipt($goods['lot'], $first, '4.000');
        $second = $this->receivePurchase($goods['purchase'], '6.000');
        $this->linkReceipt($goods['lot'], $second, '6.000');

        $this->assertOriginalAcquisition($goods, $snapshot);
        $this->assertSame(1, $this->countRows('acquisition_lots'));
        $this->assertSame(2, $this->countRows('purchase_receipt_lines'));
        $this->assertSame('10.000', $this->lotData($goods)['received_backed_quantity']);
    }

    public function test_link_failure_rolls_back_its_enclosing_receipt_transaction(): void
    {
        // Inject a failure after the real link INSERT, so this exercises rollback of actual partial
        // link work as well as the receipt/Inventory work preceding it in the owning transaction.
        $goods = $this->goods();
        $before = $this->receiptState();
        $table = (new OwnershipReceiptLink())->getTable();
        $armed = true;
        $observed = false;
        $failure = new RuntimeException('Injected receipt link failure.');
        DB::listen(static function (QueryExecuted $query) use ($table, &$armed, &$observed, $failure): void {
            if ($armed && str_starts_with(strtolower($query->sql), 'insert into `'.$table.'`')) {
                $observed = true;
                throw $failure;
            }
        });
        try {
            DB::transaction(function () use ($goods): void {
                $receipt = $this->receivePurchase($goods['purchase'], '4.000');
                app(OwnershipService::class)->linkPurchaseReceipt($receipt['receiptLine']->code);
            });
            $this->fail('The real link write must reach the injected fault.');
        } catch (RuntimeException $error) {
            $this->assertSame($failure, $error);
        } finally {
            $armed = false;
        }

        $this->assertTrue($observed, 'The fault must follow a real link INSERT, not an earlier setup error.');
        $this->assertSame($before, $this->receiptState());
        $this->assertSame(0, DB::table('tenant_idempotency_keys')->where('tenant_id', $this->ownershipFixture['tenant']->id)
            ->where('status', 'processing')->count());
        $this->assertBalances((int) $goods['lot']->owned_item_id, '10.000');
        $this->assertNoStock($goods['item']);
    }

    public function test_trusted_auto_link_uses_real_receipt_provenance(): void
    {
        // The trusted caller already knows the unambiguous purchase source and actual receipt.
        // The proposed internal adapter must use those facts and return data inside its caller transaction.
        $goods = $this->goods();
        $result = DB::transaction(function () use ($goods) {
            $receipt = $this->receivePurchase($goods['purchase'], '10.000');
            return app(OwnershipService::class)->linkPurchaseReceipt($receipt['receiptLine']->code);
        });
        $this->assertSame([$goods['lot']->code], $result->lotCodes);
        $this->assertSame('10.000', $this->lotData($goods)['received_backed_quantity']);
        $this->assertSame('0.000', $this->lotData($goods)['pending_link_quantity']);
        $this->assertBalances((int) $goods['lot']->owned_item_id, '10.000');
    }
}
