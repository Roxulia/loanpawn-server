<?php

namespace Tests\Feature\PawnModule;

use App\DataObjects\RequestObjects\{InventoryListFilter, InventoryLocationCreate, InventoryMoveRequest, PawnCollateralItemCreate};
use App\Exceptions\{AlreadyUpdatedException, InvalidTenantRequest, TenantNotFound};
use App\Models\CoreModule\MaterialType;
use App\Models\InventoryModule\{InventoryItem, InventoryMovement};
use App\Models\PawnModule\{PawnCollateralItem, PawnLoanContractSlip};
use App\Services\PawnModule\CollateralItemService;
use App\Services\PawnModule\LoanContractServices\ExpirationService;
use App\Services\TenantModule\InventoryService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Tests\Support\{InventoryMySqlTestCase, PawnInventoryFixture};

class PawnInventoryIntegrationTest extends InventoryMySqlTestCase
{
    use PawnInventoryFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionPawnTenant();
    }

    public function test_pawn_creation_receives_exact_custody_and_keeps_financial_ownership_separate(): void
    {
        $slip = $this->createPawnSlip();
        $collateral = PawnCollateralItem::query()->where('loan_contract_id', $slip->id)->sole();
        $item = InventoryItem::query()->findOrFail($collateral->inventory_item_id);
        $detail = app(InventoryService::class)->itemDetail($item->code);
        $this->assertSame('2.000', $detail->totalQuantity);
        $this->assertSame(1, $detail->updateKey);
        $this->assertCount(1, $detail->locations);
        $this->assertTrue($detail->locations[0]->location->isDefault);
        $this->assertSame(1, InventoryMovement::query()->where('source_type', 'PawnCollateralItem')->where('source_code', $collateral->code)->count());
        $this->assertSame(0, DB::table('owned_items')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame('990000.0000', $this->account->fresh()->balance);
        $this->assertGreaterThan(0, DB::table('tenant_accounting_transactions')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_normal_and_jewellery_with_identical_names_remain_distinct_items(): void
    {
        $material = MaterialType::query()->firstOrCreate(['tenant_id' => null, 'code' => 'gold'], ['name' => 'Gold', 'is_default' => true]);
        $slip = $this->createPawnSlip([
            new PawnCollateralItemCreate(type: 'Normal', name: 'Similar item', quantity: 2),
            new PawnCollateralItemCreate(type: 'Jewellery', name: 'Similar item', materialTypeId: $material->id, kyat: 1),
        ]);
        $items = PawnCollateralItem::query()->where('loan_contract_id', $slip->id)->get();
        $this->assertCount(2, $items->pluck('inventory_item_id')->unique());
        $this->assertSame(2, InventoryItem::query()->where('name', 'Similar item')->count());
        $this->assertSame(['2.000', '1.000'], $items->map(fn ($item) => app(InventoryService::class)->itemDetail($item->inventoryItem->code)->totalQuantity)->all());
    }

    public function test_retrying_custody_receipt_returns_the_existing_item_without_extra_stock(): void
    {
        $slip = $this->createPawnSlip();
        $collateral = PawnCollateralItem::query()->where('loan_contract_id', $slip->id)->sole();
        $service = app(InventoryService::class);
        $again = $service->receivePawnCollateral($collateral);
        $this->assertSame($collateral->inventory_item_id, $again->id);
        $this->assertSame(1, InventoryMovement::query()->count());
        $this->assertSame('2.000', $service->itemDetail($again->code)->totalQuantity);
        $this->assertSame(1, $again->update_key);
    }

    public function test_failure_after_custody_rolls_back_slip_inventory_and_finance(): void
    {
        $startingBalance = $this->account->balance;
        // Fault injection happens after actual Inventory writes, not in a mocked Inventory service.
        DB::listen(static function (QueryExecuted $query): void {
            if (str_starts_with(strtolower($query->sql), 'insert into `pawn_interest_payments`')) throw new \RuntimeException('Injected failure after custody');
        });
        try {
            $this->createPawnSlip();
            $this->fail('The injected failure must abort Pawn creation.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected failure after custody', $exception->getMessage());
        }
        $this->assertSame(0, PawnLoanContractSlip::query()->count());
        $this->assertSame(0, PawnCollateralItem::query()->count());
        $this->assertSame(0, InventoryItem::query()->count());
        $this->assertSame(0, InventoryMovement::query()->count());
        $this->assertSame($startingBalance, $this->account->fresh()->balance);
        $this->assertSame(0, DB::table('tenant_idempotency_keys')->where('tenant_id', $this->tenant->id)->where('operation', 'inventory.receive')->count());
    }

    public function test_move_preserves_pawn_link_and_rejects_stale_item_version(): void
    {
        $slip = $this->createPawnSlip();
        $collateral = PawnCollateralItem::query()->where('loan_contract_id', $slip->id)->sole();
        $service = app(InventoryService::class);
        $item = $service->itemDetail($collateral->inventoryItem->code);
        $location = $service->createLocation(new InventoryLocationCreate(name: 'Test Vault', type: 'VAULT'));
        $request = new InventoryMoveRequest($item->code, $item->locations[0]->location->code, $location['code'], '1.000', $item->updateKey);
        $service->moveStock($request);
        $current = $service->itemDetail($item->code);
        $this->assertSame('2.000', $current->totalQuantity);
        $this->assertSame(2, $current->updateKey);
        $this->assertSame($collateral->inventory_item_id, $collateral->fresh()->inventory_item_id);
        $this->assertSame(['1.000', '1.000'], array_column($current->toArray()['locations'], 'quantity'));
        $this->expectException(AlreadyUpdatedException::class);
        $service->moveStock($request);
    }

    public function test_other_tenant_cannot_read_or_receive_the_collateral(): void
    {
        $slip = $this->createPawnSlip();
        $collateral = PawnCollateralItem::query()->where('loan_contract_id', $slip->id)->sole();
        $code = $collateral->inventoryItem->code;
        $this->provisionPawnTenant();
        $service = app(InventoryService::class);
        $this->assertSame(0, $service->itemSummaryPage(new InventoryListFilter())->total);
        try { $service->itemDetail($code); $this->fail('Cross-tenant detail must fail.'); }
        catch (TenantNotFound $exception) { $this->assertSame(404, $exception->statusCode()); }
        $this->expectException(InvalidTenantRequest::class);
        $service->receivePawnCollateral($collateral);
    }

    public function test_expiry_changes_pawn_status_without_duplicating_or_issuing_inventory(): void
    {
        $slip = $this->createPawnSlip();
        $model = PawnLoanContractSlip::query()->findOrFail($slip->id);
        $model->update(['expire_at' => CarbonImmutable::yesterday()]);
        $this->assertSame(1, app(ExpirationService::class)->checkCurrentTenant());
        $this->assertSame('expired', $model->fresh()->status);
        $collateral = PawnCollateralItem::query()->where('loan_contract_id', $slip->id)->sole();
        $this->assertSame('expired', $collateral->item_status);
        $this->assertSame('2.000', app(InventoryService::class)->itemDetail($collateral->inventoryItem->code)->totalQuantity);
        $this->assertSame(1, InventoryMovement::query()->count());
    }

    public function test_pawn_uploaded_photo_is_signed_for_inventory_and_missing_file_has_no_url(): void
    {
        // Use a real test filesystem and signed URL builder, retaining the real upload utility.
        Storage::fake('local');
        Storage::disk('local')->buildTemporaryUrlsUsing(fn ($path, $expiration) =>
            \Illuminate\Support\Facades\URL::temporarySignedRoute('storage.local', $expiration, ['path' => $path]));
        $image = UploadedFile::fake()->createWithContent('collateral.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a3ioAAAAASUVORK5CYII='));
        $slip = $this->createPawnSlip([new PawnCollateralItemCreate(type: 'Normal', name: 'Photo phone', imageReference: $image)]);
        $collateral = PawnCollateralItem::query()->where('loan_contract_id', $slip->id)->sole();
        Storage::disk('local')->assertExists($collateral->image_url);
        $service = app(InventoryService::class);
        $detail = $service->itemDetail($collateral->inventoryItem->code);
        $this->assertNotNull($detail->imageUrl);
        $this->assertStringContainsString('signature=', $detail->imageUrl);
        $this->assertNotNull($detail->imageUrlExpiresAt);
        $page = $service->itemSummaryPage(new InventoryListFilter());
        $this->assertSame($detail->imageUrl, $page->items[0]->imageUrl);
        $this->assertArrayNotHasKey('collateral_image_path', $detail->toArray());
        Storage::disk('local')->delete($collateral->image_url);
        $this->assertNull($service->itemDetail($detail->code)->imageUrl);
    }

    public function test_collateral_redemption_currently_preserves_stock_pending_part_six(): void
    {
        // Characterize the existing deferred integration without claiming redemption issues stock.
        $slip = $this->createPawnSlip();
        $model = PawnLoanContractSlip::query()->findOrFail($slip->id);
        app(CollateralItemService::class)->redeemProcess($model);
        $collateral = PawnCollateralItem::query()->where('loan_contract_id', $slip->id)->sole();
        $this->assertSame('redeemed', $collateral->item_status);
        $this->assertSame('2.000', app(InventoryService::class)->itemDetail($collateral->inventoryItem->code)->totalQuantity);
        $this->assertSame(1, InventoryMovement::query()->count());
    }
}
