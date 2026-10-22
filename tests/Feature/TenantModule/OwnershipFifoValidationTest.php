<?php

namespace Tests\Feature\TenantModule;

use App\Exceptions\TenantNotFound;
use App\Services\TenantModule\{InventoryService, OwnershipService};
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\OwnershipFifoTestCase;

/** Invalid input must preserve all source, quantity, identity and replay records. */
class OwnershipFifoValidationTest extends OwnershipFifoTestCase
{
    public function test_missing_inventory_error_is_propagated_from_inventory_service(): void
    {
        // An exception identity check proves delegation rather than recreating a matching local error.
        $failure = new TenantNotFound(__('inventory.item_not_found'));
        $inventory = Mockery::mock(InventoryService::class)->makePartial();
        $inventory->shouldReceive('findForOwnershipByCode')->once()->with('MISSING-FIFO')->andThrow($failure);
        $this->app->instance(InventoryService::class, $inventory);
        $this->app->forgetInstance(OwnershipService::class);
        $before = $this->receiptState();
        try {
            DB::transaction(fn () => $this->fifo('MISSING-FIFO', '1.000'));
            $this->fail('The Inventory resolver must reject the missing item.');
        } catch (TenantNotFound $error) {
            $this->assertSame($failure, $error);
        }
        $this->assertSame($before, $this->receiptState());
    }

    public function test_unknown_and_foreign_inventory_codes_are_not_found(): void
    {
        // Real resolution must conceal foreign items, even if their exact public code is known.
        $this->rejectFifo('MISSING-FIFO', '1.000', [], 'inventory.item_not_found', TenantNotFound::class);
        $home = $this->ownershipFixture;
        $this->createTenant();
        $foreign = $this->fifoGoods();
        $this->setTenant($home);
        $this->rejectFifo($foreign['item']->code, '1.000', [], 'inventory.item_not_found', TenantNotFound::class);
    }

    public function test_existing_inventory_without_ownership_uses_ownership_missing_error(): void
    {
        // A valid physical identity does not establish a tenant entitlement or an eligible lot.
        $item = $this->createItem();
        $this->rejectFifo($item->code, '1.000', [], 'ownership.not_found', TenantNotFound::class);
    }

    public function test_empty_and_insufficient_eligible_stock_reject_completely(): void
    {
        // First no receipt exists; after receipt/linking the same lot still cannot supply 3.001.
        $waiting = $this->fifoGoods(received: false);
        $this->rejectFifo($waiting['item']->code, '0.001', [], 'ownership.insufficient_lot_balance');
        $goods = $this->fifoGoods();
        $this->rejectFifo($goods['item']->code, '3.001', [], 'ownership.insufficient_lot_balance');
    }

    #[DataProvider('invalidQuantities')]
    public function test_invalid_quantity_rejects(mixed $quantity, string $message): void
    {
        // Use a fully eligible item so malformed input cannot pass because a different prerequisite fails.
        $goods = $this->fifoGoods();
        $this->rejectFifo($goods['item']->code, $quantity, [], $message);
    }

    #[DataProvider('invalidSerialSelections')]
    public function test_invalid_serial_list_rejects(string $quantity, array $serials, string $message): void
    {
        // FIFO must neither deduplicate silently nor replace an invalid serial with another available piece.
        $goods = $this->fifoGoods(quantity: '2.000', serials: ['FIFO-A', 'FIFO-B']);
        $this->rejectFifo($goods['item']->code, $quantity, $serials, $message);
    }

    public function test_piece_codes_are_not_accepted_as_human_serial_numbers(): void
    {
        // Public physical codes are returned for downstream services, but input here is the serial identifier.
        $goods = $this->fifoGoods(quantity: '1.000', serials: ['FIFO-IDENTIFIER']);
        $this->rejectFifo($goods['item']->code, '1.000', $goods['pieces'], 'ownership.piece_selection_invalid');
    }

    public function test_foreign_item_and_foreign_tenant_serials_reject(): void
    {
        // Each serial is real and eligible elsewhere, which must not authorize it for this requested item.
        $goods = $this->fifoGoods(quantity: '1.000', serials: ['FIFO-LOCAL']);
        $other = $this->fifoGoods(quantity: '1.000', serials: ['FIFO-OTHER-ITEM']);
        $this->rejectFifo($goods['item']->code, '1.000', $other['serials'], 'ownership.piece_selection_invalid');
        $home = $this->ownershipFixture;
        $this->createTenant();
        $foreign = $this->fifoGoods(quantity: '1.000', serials: ['FIFO-FOREIGN']);
        $this->setTenant($home);
        $this->rejectFifo($goods['item']->code, '1.000', $foreign['serials'], 'ownership.piece_selection_invalid');
    }

    public function test_unlinked_piece_and_mixed_valid_invalid_selection_reject(): void
    {
        // Both serials are physically present, but only one has a current Ownership membership.
        $goods = $this->fifoGoods(quantity: '1.000', serials: ['FIFO-LINKED']);
        $this->fifoGoods($goods['item'], '1.000', serials: ['FIFO-UNLINKED'], linked: false);
        $this->rejectFifo($goods['item']->code, '1.000', ['FIFO-UNLINKED'], 'ownership.piece_selection_invalid');
        $this->rejectFifo($goods['item']->code, '2.000', ['FIFO-LINKED', 'FIFO-UNLINKED'], 'ownership.piece_selection_invalid');
    }

    #[DataProvider('pieceRestrictions')]
    public function test_unavailable_selected_piece_rejects(string $restriction): void
    {
        // Exercise real reservation/disposal primitives; a free replacement piece must not be substituted.
        $goods = $this->fifoGoods(quantity: '1.000', serials: ['FIFO-RESTRICTED']);
        $this->fifoGoods($goods['item'], '1.000', serials: ['FIFO-FREE']);
        if ($restriction === 'issued') {
            $this->sellPiece($goods + ['piece' => $goods['pieces'][0]]);
        } else {
            $source = $restriction === 'reserve' ? $this->sale($goods, '1.000') : 'FIFO-PLEDGE';
            $this->change($goods, $restriction, '1.000', $source, $goods['pieces']);
        }
        $this->rejectFifo($goods['item']->code, '1.000', $goods['serials'], 'ownership.piece_selection_invalid');
    }

    public function test_quantity_goods_reject_serial_input(): void
    {
        // Extra identity input must not be ignored for fungible stock.
        $goods = $this->fifoGoods();
        $this->rejectFifo($goods['item']->code, '1.000', ['FIFO-A'], 'inventory.unit_selection_invalid');
    }

    public static function invalidQuantities(): array
    {
        // Match existing exact InventoryQuantity validation messages instead of introducing another parser.
        return ['zero' => ['0', 'inventory.quantity_positive'], 'negative' => ['-1', 'inventory.quantity_positive'],
            'malformed' => ['one', 'inventory.quantity_invalid'], 'empty' => ['', 'inventory.quantity_invalid'],
            'precision' => ['0.0001', 'inventory.quantity_precision'],
            'overflow' => ['100000000000.000', 'inventory.quantity_out_of_range']];
    }

    public static function invalidSerialSelections(): array
    {
        // Count uses distinct identifiers; whitespace must not disguise duplicate input.
        return ['missing' => ['1.000', [], 'ownership.piece_selection_invalid'],
            'duplicate' => ['2.000', ['FIFO-A', 'FIFO-A'], 'ownership.piece_selection_invalid'],
            'trimmed duplicate' => ['2.000', ['FIFO-A', ' FIFO-A '], 'ownership.piece_selection_invalid'],
            'too few' => ['2.000', ['FIFO-A'], 'ownership.piece_selection_invalid'],
            'too many' => ['1.000', ['FIFO-A', 'FIFO-B'], 'ownership.piece_selection_invalid'],
            'unknown' => ['1.000', ['NO-SUCH-SERIAL'], 'ownership.piece_selection_invalid'],
            'blank' => ['1.000', [''], 'ownership.piece_selection_invalid'],
            'fraction' => ['1.500', ['FIFO-A'], 'inventory.serialized_quantity']];
    }

    public static function pieceRestrictions(): array
    {
        return ['reserved' => ['reserve'], 'pledged' => ['pledge'], 'issued' => ['issued']];
    }
}
