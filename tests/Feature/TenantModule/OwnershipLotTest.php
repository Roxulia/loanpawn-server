<?php

namespace Tests\Feature\TenantModule;

use App\DTOs\Ownership\Public\RequestObjects\OwnershipLotFilter;
use App\Exceptions\InvalidTenantRequest;
use App\Models\CoreModule\Currency;
use App\Models\FinancialAccount;
use App\Models\InventoryModule\InventoryItem;
use App\Models\OwnershipModule\{AcquisitionLot, OwnedItem};
use App\Services\TenantModule\{InventoryService, OwnershipService};
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\OwnershipPurchaseTestCase;

/** OWN-06c: immutable acquisition snapshots, separate currencies, and historical reads. */
class OwnershipLotTest extends OwnershipPurchaseTestCase
{
    public function test_purchases_keep_separate_cost_currency_and_date_snapshots(): void
    {
        // Buy 10 units twice using the same item identity: first in MMK, then in USD.
        // Separate prices and settlement dates must produce separate historical acquisition lots.
        $item = $this->createItem();
        $first = $this->purchase($item, $this->ownershipFixture['currency'], '12.34', '123.40', '2026-10-11');
        $second = $this->purchase($item, $this->createCurrency('USD'), '99.99', '999.90', '2026-10-12');
        $cash = $this->accountBalances();

        // Capture the first stored lot before acquiring the second purchase, so later assertions
        // can detect a rewritten cost, date or source even if the lot count remains correct.
        $firstLot = $this->assertAcquisition($first, $this->acquire($first), expectedDate: '2026-10-11');
        $firstSnapshot = $this->lotSnapshots();
        $secondLot = $this->assertAcquisition($second, $this->acquire($second), expectedCost: '99.9900');

        // The item may own 20 units in total, but each lot must keep its own cost and currency.
        // Neither acquisition should change cash balances or create physical stock before receipt.
        $this->assertNotSame($firstLot->code, $secondLot->code);
        $this->assertSame((int) $firstLot->owned_item_id, (int) $secondLot->owned_item_id);
        $this->assertSame($firstSnapshot[$firstLot->code], $this->lotSnapshots()[$firstLot->code]);
        $this->assertSame('MMK', $firstLot->currency_code);
        $this->assertSame('USD', $secondLot->currency_code);
        $this->assertSame('12.3400', $firstLot->unit_cost_basis);
        $this->assertSame('99.9900', $secondLot->unit_cost_basis);
        $this->assertNull($firstLot->estimated_unit_value);
        $this->assertNull($secondLot->estimated_unit_value);
        $this->assertBalances((int) $firstLot->owned_item_id, '20.000');
        $this->assertSame(2, $this->countRows('acquisition_lots'));
        $this->assertSame($cash, $this->accountBalances());
        $this->assertNoStock($item);
    }

    public function test_receipt_and_linking_do_not_change_original_lot(): void
    {
        // Pay for and acquire 10 units before any goods arrive. Save the original lot row,
        // including its cost, acquisition date and currency, before adding receipt provenance.
        $purchase = $this->paidPurchase($this->createItem());
        $lot = $this->assertAcquisition($purchase, $this->acquire($purchase));
        $before = $this->lotSnapshots();

        // Receive only 4 units, check the lot immediately, then link that receipt to the same lot.
        // Checking both steps identifies whether receipt or linking incorrectly edits the snapshot.
        $receipt = $this->receivePurchase($purchase, '4.000');
        $this->assertSame($before, $this->lotSnapshots());
        $this->linkReceipt($lot, $receipt, '4.000');

        // Ownership stays at the 10 units already acquired while physical stock becomes 4 units.
        // Linking must preserve the original lot row rather than acquiring those 4 units again.
        $this->assertSame($before, $this->lotSnapshots());
        $this->assertBalances((int) $lot->owned_item_id, '10.000');
        $this->assertSame(1, $this->countRows('acquisition_lots'));
        $this->assertSame('4.000', app(InventoryService::class)->itemDetail($purchase['item']->code)->totalQuantity);
    }

    public function test_sale_reservation_and_consumption_keep_original_lot(): void
    {
        // Receive and link all 10 owned units, then create a real confirmed sale line for 2 units.
        // These source records support the Ownership operation without running full Quick Sell.
        $purchase = $this->paidPurchase($this->createItem());
        $lot = $this->assertAcquisition($purchase, $this->acquire($purchase));
        $this->linkReceipt($lot, $this->receivePurchase($purchase, '10.000'), '10.000');
        $line = $this->saleLine($lot, $purchase['item']);
        $owned = OwnedItem::query()->findOrFail($lot->owned_item_id);
        $before = $this->lotSnapshots();
        $service = app(OwnershipService::class);

        // Reserve 2 units, then consume 1 from that reservation. Check the historical lot after
        // each operation so a changed original quantity, cost, date or currency is caught separately.
        $service->reserveSale($owned->code, $lot->code, '2.000', $line->code);
        $this->assertSame($before, $this->lotSnapshots());
        $service->consumeSaleReservation($owned->code, $lot->code, '1.000', $line->code);

        // Expected Ownership balances are now 9 owned, 1 reserved and 0 pledged, while the lot's
        // original acquired quantity remains 10. Payment and physical issue are separate workflow tests.
        $this->assertSame($before, $this->lotSnapshots());
        $rows = DB::table('ownership_movements')->where('tenant_id', $this->ownershipFixture['tenant']->id)
            ->where('acquisition_lot_id', $lot->id);
        $this->assertSame('9.000', (string) (clone $rows)->sum('owned_delta'));
        $this->assertSame('1.000', (string) (clone $rows)->sum('reserved_delta'));
        $this->assertSame('0.000', (string) (clone $rows)->sum('pledged_delta'));
    }

    public function test_historical_lot_keeps_inactive_currency_and_original_cost(): void
    {
        // Acquire the lot while MMK is active, then deactivate MMK before reading its history.
        // The currency is no longer eligible for new acquisition, but the saved lot must remain readable.
        $purchase = $this->paidPurchase($this->createItem());
        $lot = $this->assertAcquisition($purchase, $this->acquire($purchase));
        $owned = OwnedItem::query()->findOrFail($lot->owned_item_id);
        $this->ownershipFixture['currency']->update(['is_active' => false]);
        $before = $this->businessState(array_keys($this->ownershipFixtures));

        $page = app(OwnershipService::class)->lotPage($owned->code,
            OwnershipLotFilter::fromValidated(['currency_code' => 'MMK']));
        $data = $page->toArray();

        // The read must return the original MMK cost and settlement date without converting values
        // or changing any stored business data merely because the currency is now inactive.
        $this->assertSame(1, $data['total']);
        $this->assertSame($lot->code, $data['items'][0]['code']);
        $this->assertSame('MMK', $data['items'][0]['currency_code']);
        $this->assertSame('12.3400', $data['items'][0]['unit_cost_basis']);
        $this->assertSame('2026-10-12', $data['items'][0]['acquired_at']);
        $this->assertSame($before, $this->businessState(array_keys($this->ownershipFixtures)));
    }

    #[DataProvider('unavailableCurrencies')]
    public function test_new_acquisition_rejects_unavailable_currency(string $state): void
    {
        // Start with a valid paid purchase, then make its currency inactive or soft-deleted.
        // Acquisition must check availability at the time of acquisition, not only when payment occurred.
        $purchase = $this->paidPurchase($this->createItem());
        $currency = $this->ownershipFixture['currency'];
        if ($state === 'inactive') {
            $currency->update(['is_active' => false]);
        } else {
            $currency->delete();
        }
        // The shared assertion checks the 422 rejection, unchanged business rows and versions,
        // and absence of a completed or stranded processing replay for this attempt.
        $this->assertRejected($purchase['line']->code, InvalidTenantRequest::class);
    }

    public function test_visible_currency_code_is_not_limited_to_three_letters(): void
    {
        // TEST4 is a valid visible code under the existing alphanumeric currency rules.
        // Acquisition must preserve it rather than introducing a new three-letter-only restriction.
        $purchase = $this->purchase($this->createItem(), $this->createCurrency('TEST4'), '12.34', '123.40', '2026-10-12');
        $lot = $this->assertAcquisition($purchase, $this->acquire($purchase));
        $this->assertSame('TEST4', $lot->currency_code);
        $this->assertBalances((int) $lot->owned_item_id, '10.000');
    }

    public static function unavailableCurrencies(): array
    {
        // Run the same rejection assertions for two distinct availability states:
        // an existing inactive currency and a currency hidden by soft deletion.
        return ['inactive' => ['inactive'], 'deleted' => ['deleted']];
    }

    private function purchase(InventoryItem $item, Currency $currency, string $price, string $paid, string $date): array
    {
        // Temporarily select the requested currency and a matching cash account for source setup.
        // Persist purchase/payment facts only; this helper performs no exchange or financial posting.
        $original = $this->ownershipFixture;
        $account = $this->accountFor($currency);
        try {
            $this->setTenant(array_replace($original, ['currency' => $currency, 'account' => $account]));
            $purchase = $this->createPurchase($item, unitPrice: $price, paid: $paid);
            $purchase['order']->update(['ordered_at' => '2026-10-10']);
            $purchase['payment']->update(['paid_at' => $date]);

            return $purchase;
        } finally {
            // Restore the original fixture, tenant context and actor even if source setup fails.
            $this->setTenant($original);
        }
    }

    private function createCurrency(string $code): Currency
    {
        // Create an active currency owned by this test tenant. This lets a test deactivate or
        // delete its own currency later without changing shared platform data or another tenant.
        $tenantId = (int) $this->ownershipFixture['tenant']->id;

        return Currency::query()->create(['tenant_id' => $tenantId, 'scope_key' => 'tenant:'.$tenantId,
            'code' => $code, 'name' => 'Test '.$code, 'decimal_precision' => 2, 'rounding_mode' => 'HALF_UP', 'is_active' => true]);
    }

    private function accountFor(Currency $currency): FinancialAccount
    {
        // Reuse the fixture account if its currency matches. Otherwise create a separate funded
        // account so a USD purchase is not accidentally backed by an MMK payment account.
        $account = $this->ownershipFixture['account'];
        if ((int) $account->currency_id === (int) $currency->id) {
            return $account;
        }

        return FinancialAccount::query()->create(['tenant_id' => $this->ownershipFixture['tenant']->id,
            'account_type_id' => $account->account_type_id, 'currency_id' => $currency->id,
            'account_code' => 'LC'.bin2hex(random_bytes(6)), 'account_name' => 'Lot test '.$currency->code,
            'balance' => '1000000.0000', 'is_active' => true, 'is_default' => false, 'is_deleted' => false,
            'allow_negative_balance' => false]);
    }

    private function accountBalances(): array
    {
        // Keep balances keyed by account ID so MMK and USD cash are checked independently.
        // Adding them together would hide both currency mixing and offsetting account changes.
        return DB::table('financial_accounts')->where('tenant_id', $this->ownershipFixture['tenant']->id)
            ->orderBy('id')->pluck('balance', 'id')->all();
    }

}
