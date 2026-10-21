<?php

namespace Tests\Feature\TenantModule;

use App\DataObjects\RequestObjects\{InventoryLocationCreate, InventoryLocationUpdate, InventoryMoveRequest, InventoryReceiveRequest};
use App\Exceptions\{AlreadyUpdatedException, IdempotencyKeyConflict, IdempotentReplayResponse, InvalidTenantRequest};
use App\Models\InventoryModule\InventoryMovement;
use App\Models\PawnModule\PawnCollateralItem;
use App\Repository\InventoryRepository;
use App\Services\TenantModule\InventoryService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\PawnInventoryTestCase;

class InventoryMySqlVerificationTest extends PawnInventoryTestCase
{
    public function test_one_thousandth_shortfall_is_rejected_without_writes_and_exact_exhaustion_succeeds(): void
    {
        [$service, $item, $destination] = $this->stock();
        try {
            $service->moveStock(new InventoryMoveRequest($item->code, $item->locations[0]->location->code, $destination['code'], '2.001', $item->updateKey));
            $this->fail('A one-thousandth shortfall must fail.');
        } catch (InvalidTenantRequest) {
            $this->assertSame(1, InventoryMovement::query()->count());
            $this->assertSame(1, $service->itemDetail($item->code)->updateKey);
        }
        $service->moveStock(new InventoryMoveRequest($item->code, $item->locations[0]->location->code, $destination['code'], '2.000', $item->updateKey));
        $current = $service->itemDetail($item->code);
        $balances = collect($current->locations)->keyBy(fn ($row) => $row->location->code);
        $this->assertSame('0.000', $balances[$item->locations[0]->location->code]->quantity);
        $this->assertSame('2.000', $balances[$destination['code']]->quantity);
    }

    public function test_inbound_adjustment_checks_identifiers_once_and_duplicate_failure_preserves_stock(): void
    {
        // Provision a serialized item through the normal receipt orchestration.
        [$service, $pawnItem] = $this->stock();
        $location = $pawnItem->locations[0]->location->code;
        $received = $service->receive(new InventoryReceiveRequest(locationCode: $location, quantity: '1',
            name: 'Adjustment verification', trackingMode: 'SERIALIZED', unitCode: 'unit', serializedItemIdentifiers: ['adjust-original']));
        $request = new \App\DataObjects\RequestObjects\InventoryAdjustmentRequest(
            $received['inventory_item_code'], $location, '1', 'IN', 'Stock count correction', serializedItemIdentifiers: ['adjust-added']);

        // Normalization must perform one batch availability lookup, rather than one per validation layer.
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        try {
            $adjusted = $service->adjustStock($request);
            $checks = array_filter(DB::connection()->getQueryLog(), static fn (array $query): bool =>
                str_contains($query['query'], '`identifier` in') && in_array('adjust-added', $query['bindings'], true));
            $this->assertCount(1, $checks);
        } finally {
            DB::connection()->disableQueryLog();
        }
        $this->assertSame('2.000', $service->itemDetail($received['inventory_item_code'])->totalQuantity);

        // Removing the redundant validation must retain identifier uniqueness and rollback behavior.
        try {
            $service->adjustStock($request);
            $this->fail('An existing identifier must still be rejected.');
        } catch (\App\Exceptions\DuplicateValueFound) {
            $detail = $service->itemDetail($received['inventory_item_code']);
            $this->assertSame('2.000', $detail->totalQuantity);
            $this->assertSame($adjusted['update_key'], $detail->updateKey);
        }
    }

    public function test_public_idempotency_replays_original_result_and_changed_payload_conflicts(): void
    {
        [$service, $item, $destination] = $this->stock();
        $request = new InventoryMoveRequest($item->code, $item->locations[0]->location->code, $destination['code'], '0.001', $item->updateKey, idempotencyKey: 'verification-move');
        $result = $service->moveStock($request);
        try { $service->moveStock($request); $this->fail('Public replay must retain its HTTP response.'); }
        catch (IdempotentReplayResponse $replay) {
            // MySQL JSON may reorder object keys; replay must preserve all field values.
            $this->assertEquals($result, $replay->responseData());
            $this->assertSame('true', $replay->render(request())->headers->get('Idempotent-Replay'));
        }
        $this->assertSame(2, InventoryMovement::query()->count());
        $this->expectException(IdempotencyKeyConflict::class);
        $service->moveStock(new InventoryMoveRequest($item->code, $item->locations[0]->location->code, $destination['code'], '0.002', $item->updateKey, idempotencyKey: 'verification-move'));
    }

    public function test_default_and_stocked_locations_cannot_deactivate_but_empty_location_can(): void
    {
        [$service, $item, $destination] = $this->stock();
        $source = $item->locations[0]->location;
        try { $service->updateLocationByCode(new InventoryLocationUpdate($source->code, $source->updateKey, isActive: false)); $this->fail('Default must stay active.'); }
        catch (InvalidTenantRequest) { $this->assertTrue($service->itemDetail($item->code)->locations[0]->location->isActive); }
        $service->moveStock(new InventoryMoveRequest($item->code, $source->code, $destination['code'], '2.000', $item->updateKey));
        try { $service->updateLocationByCode(new InventoryLocationUpdate($destination['code'], 0, isActive: false)); $this->fail('Stocked location must stay active.'); }
        catch (InvalidTenantRequest) { $this->assertSame(2, InventoryMovement::query()->count()); }
        $empty = $service->createLocation(new InventoryLocationCreate('Empty verification vault', 'VAULT'));
        $updated = $service->updateLocationByCode(new InventoryLocationUpdate($empty['code'], 0, isActive: false));
        $this->assertFalse($updated['is_active']);
        $this->assertSame(1, $updated['update_key']);
        $this->expectException(AlreadyUpdatedException::class);
        $service->updateLocationByCode(new InventoryLocationUpdate($empty['code'], 0, name: 'Stale rename'));
    }

    public function test_other_mysql_connection_cannot_take_an_owned_item_anchor(): void
    {
        [$service, $item] = $this->stock();
        $service->prelockItems([$item->code]);
        $id = PawnCollateralItem::query()->sole()->inventory_item_id;
        // A second physical PDO connection proves InnoDB enforces the anchor lock.
        config(['database.connections.inventory_contender' => config('database.connections.inventory_testing')]);
        $other = DB::connection('inventory_contender');
        $this->assertNotSame(DB::connection()->getPdo(), $other->getPdo());
        $other->beginTransaction();
        try {
            $other->select('SELECT id FROM inventory_items WHERE id = ? FOR UPDATE NOWAIT', [$id]);
            $this->fail('The competing connection must not acquire this item anchor.');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertSame(3572, $exception->errorInfo[1]);
        } finally {
            $other->rollBack();
            DB::disconnect('inventory_contender');
        }
        $this->assertSame('2.000', $service->itemDetail($item->code)->totalQuantity);
    }

    public function test_checkpoint_watermark_does_not_hide_current_month_receipts_or_later_moves(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
        [$service, $item, $destination] = $this->stock();
        $source = $item->locations[0]->location;
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-02 12:00:00', 'UTC'));
        $service->receive(new InventoryReceiveRequest(locationCode: $source->code, quantity: '0.500', inventoryItemCode: $item->code));
        $repository = app(InventoryRepository::class);
        $model = $service->prelockItems([$item->code])->get($item->code);
        $cutoff = CarbonImmutable::parse('2026-11-01', 'UTC');
        $balances = $repository->movementBalancesForItems([$model->id], null, $cutoff);
        $checkpoint = $repository->createMonthlyCheckpoint($model->id, '2026-10-01', 'UTC', $cutoff, $repository->movementWatermark($model->id), $balances);
        $this->assertSame('2.000', (string) DB::table('inventory_monthly_checkpoint_balances')->where('inventory_monthly_checkpoint_id', $checkpoint->id)->value('quantity'));
        $this->assertSame('2.500', $service->itemDetail($item->code)->totalQuantity);
        $service->moveStock(new InventoryMoveRequest($item->code, $source->code, $destination['code'], '0.001', 2));
        $this->assertSame('2.500', $service->itemDetail($item->code)->totalQuantity);
        $this->assertSame('2.499', collect($service->itemDetail($item->code)->locations)->first(fn ($row) => $row->location->code === $source->code)->quantity);
    }

    public function test_disabled_manual_stock_routes_reject_real_http_requests_without_writes(): void
    {
        [$service, $item] = $this->stock();
        $this->seed(\Database\Seeders\PackageSeeder::class);
        \App\Models\PlatformModule\TenantLicense::query()->create([
            'tenant_id' => $this->tenant->id, 'license_key' => 'IT-'.bin2hex(random_bytes(6)),
            'plan_type' => 'basic', 'status' => 'active', 'starts_at' => now(),
            'expires_at' => now()->addMonth(), 'activated_at' => now(),
        ]);
        \Laravel\Sanctum\Sanctum::actingAs(\Illuminate\Support\Facades\Auth::guard('tenantuser')->user(), [], 'tenantuser');
        foreach (['receive', 'issue', 'adjust'] as $operation) {
            $this->withHeader('X-Tenant-Code', $this->tenant->tenant_code)
                ->postJson('/api/tenant/inventory/'.$operation, [
                    'inventory_item_code' => $item->code, 'location_code' => $item->locations[0]->location->code,
                    'quantity' => '0.001', 'direction' => 'OUT', 'reason' => 'Blocked test operation',
                ])->assertForbidden()->assertJsonPath('data.code', 'FEATURE_NOT_AVAILABLE_FOR_PLAN');
        }
        // Tenant resolution middleware deliberately clears its request context on completion.
        app(\App\Support\TenantContext::class)->set($this->tenant);
        $this->assertSame(1, InventoryMovement::query()->count());
        $this->assertSame('2.000', $service->itemDetail($item->code)->totalQuantity);
    }

    public function test_serialized_inventory_reservation_partial_release_and_issue_preserve_exact_membership(): void
    {
        [$service, $pawnItem, $destination] = $this->stock();
        $location = $pawnItem->locations[0]->location->code;
        // Exercise Inventory's internal reservation API only, without a Sales/Purchasing workflow.
        $received = $service->receive(new InventoryReceiveRequest(locationCode: $location, quantity: '3',
            name: 'Serialized verification stock', trackingMode: 'SERIALIZED', unitCode: 'unit',
            serializedItemIdentifiers: ['0', 'verification-2', 'verification-3']));
        $codes = $received['serialized_item_codes'];
        $item = $received['inventory_item_code'];
        $this->assertCount(3, $codes);
        $this->assertSame(array_slice($codes, 1), $service->saleSerializedItemCodes($item, $location, 2, [$codes[0]]));
        $reserved = $service->reserveForSale(new \App\DataObjects\RequestObjects\InventoryReservationRequest(
            inventoryItemCode: $item, locationCode: $location, quantity: '2', serializedItemCodes: array_slice($codes, 0, 2),
            sourceType: 'SaleOrderLine', sourceCode: 'inventory-verification-line'));
        try {
            $service->moveStock(new InventoryMoveRequest($item, $location, $destination['code'], '1', $reserved['update_key'], serializedItemCodes: [$codes[0]]));
            $this->fail('Reserved identity must not move through an unreserved command.');
        } catch (InvalidTenantRequest) { $this->assertSame($reserved['update_key'], $service->itemDetail($item)->updateKey); }
        $released = $service->releaseSaleReservation(new \App\DataObjects\RequestObjects\InventoryReservationReleaseRequest(
            inventoryItemCode: $item, locationCode: $location, reservationCode: $reserved['reservation_code'],
            quantity: '1', serializedItemCodes: [$codes[0]], expectedSourceType: 'SaleOrderLine', expectedSourceCode: 'inventory-verification-line'));
        $this->assertSame('1.000', $released['remaining_quantity']);
        $issued = $service->issueStock(new \App\DataObjects\RequestObjects\InventoryIssueRequest(
            inventoryItemCode: $item, locationCode: $location, quantity: '1', serializedItemCodes: [$codes[1]],
            reservationCode: $reserved['reservation_code'], expectedReservationSourceType: 'SaleOrderLine', expectedReservationSourceCode: 'inventory-verification-line',
            operationContext: \App\Enums\InventoryIssueContext::Sale, sourceType: 'SaleDeliveryAllocation', sourceCode: 'inventory-verification-allocation'));
        $this->assertSame('0.000', $issued['remaining_quantity']);
        $this->assertSame('2.000', $service->itemDetail($item)->totalQuantity);
        $this->assertSame($received['update_key'] + 3, $service->itemDetail($item)->updateKey);
        $stored = DB::table('inventory_reservations')->where('code', $reserved['reservation_code'])->first();
        $this->assertSame('CONSUMED', $stored->status);
        $this->assertSame([], json_decode($stored->serialized_item_codes, true));
    }

    public function test_internal_return_restores_existing_identity_and_rechecks_disposition(): void
    {
        [$service, $pawnItem] = $this->stock();
        $location = $pawnItem->locations[0]->location->code;
        $received = $service->receive(new InventoryReceiveRequest(locationCode: $location, quantity: '1',
            name: 'Return verification identity', trackingMode: 'SERIALIZED', unitCode: 'unit', serializedItemIdentifiers: ['return-verification']));
        $code = $received['inventory_item_code'];
        $codes = $received['serialized_item_codes'];
        $issued = $service->issueStock(new \App\DataObjects\RequestObjects\InventoryIssueRequest(inventoryItemCode: $code, locationCode: $location, quantity: '1', operationContext: \App\Enums\InventoryIssueContext::Manual, serializedItemCodes: $codes));
        try {
            $service->receiveSaleReturn(new \App\DataObjects\RequestObjects\InventorySaleReturnRequest($code, $location, '1', 'verification-return', $codes, expectedSellable: false));
            $this->fail('Disposition mismatch must fail under the location lock.');
        } catch (InvalidTenantRequest) { $this->assertSame('0.000', $service->itemDetail($code)->totalQuantity); }
        $request = new \App\DataObjects\RequestObjects\InventorySaleReturnRequest($code, $location, '1', 'verification-return', $codes, expectedSellable: true);
        $returned = $service->receiveSaleReturn($request);
        $this->assertSame($codes, $returned['serialized_item_codes']);
        $this->assertSame($issued['update_key'] + 1, $returned['update_key']);
        $this->assertSame('1.000', $service->itemDetail($code)->totalQuantity);
        $this->assertSame(1, DB::table('inventory_serialized_items')->where('inventory_item_id', $service->findForOwnershipByCode($code)->id)->count());
        $this->assertSame($returned['movement_codes'][0], $service->movementForSource('SaleReturnLine', 'verification-return')->code);
        $this->expectException(InvalidTenantRequest::class);
        $service->receiveSaleReturn($request);
    }

    public function test_summary_pagination_reaches_beyond_old_caps_without_per_item_queries(): void
    {
        [$service, $item] = $this->stock();
        $model = $service->findForOwnershipByCode($item->code);
        $rows = [];
        foreach (range(1, 105) as $number) {
            $rows[] = ['tenant_id' => $this->tenant->id, 'code' => 'page-'.$number,
                'unit_id' => $model->unit_id, 'tracking_mode' => 'QUANTITY', 'name' => 'Paged item '.str_pad((string) $number, 3, '0', STR_PAD_LEFT),
                'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('inventory_items')->insert($rows);
        $filter = new \App\DataObjects\RequestObjects\InventoryListFilter(page: 5, perPage: 25, search: 'Paged item');
        $page = $service->itemSummaryPage($filter);
        $this->assertSame(105, $page->total);
        $this->assertCount(5, $page->items);
        $this->assertSame('Paged item 101', $page->items[0]->name);
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        $service->itemSummaryPage(new \App\DataObjects\RequestObjects\InventoryListFilter(perPage: 1, search: 'Paged item'));
        $singleQueries = count(DB::connection()->getQueryLog());
        DB::connection()->flushQueryLog();
        $service->itemSummaryPage(new \App\DataObjects\RequestObjects\InventoryListFilter(perPage: 25, search: 'Paged item'));
        $this->assertSame($singleQueries, count(DB::connection()->getQueryLog()));
        DB::connection()->disableQueryLog();
    }
    private function stock(): array
    {
        // Arrange Pawn custody stock and a separate destination for verification.
        $slip = $this->createPawnSlip();
        $collateral = PawnCollateralItem::query()->where('loan_contract_id', $slip->id)->sole();
        $service = app(InventoryService::class);
        return [$service, $service->itemDetail($collateral->inventoryItem->code), $service->createLocation(new InventoryLocationCreate('Verification vault', 'VAULT'))];
    }

}
