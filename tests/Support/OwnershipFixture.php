<?php

namespace Tests\Support;

use App\DataObjects\RequestObjects\InventoryReceiveRequest;
use App\Models\CatalogModule\CatalogUnit;
use App\Models\CoreModule\{Currency, TenantPerson, TenantRole, TenantSetting, TenantUser};
use App\Models\{FinancialAccount, FinancialAccountTypes};
use App\Models\InventoryModule\{InventoryItem, InventoryLocation};
use App\Models\PlatformModule\{PlatformUser, Tenant};
use App\Models\OwnershipModule\{AcquisitionLot, OwnedItem, OwnershipMovement};
use App\Models\PurchasingModule\{PurchaseOrder, PurchaseOrderLine, PurchasePayment, PurchaseReceipt, PurchaseReceiptLine, PurchaseSupplier};
use App\Services\TenantModule\InventoryService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;
use LogicException;

/** Source fixtures deliberately do not perform acquisition or full business orchestration. */
trait OwnershipFixture
{
    protected array $ownershipFixtures = [];
    protected array $ownershipFixtureOwnerIds = [];
    protected array $ownershipFixtureRoleIds = [];
    protected array $ownershipFixture = [];

    protected function provisionOwnershipTenant(): array
    {
        // Track each parent immediately so committed cleanup also handles partial setup failures.
        $suffix = bin2hex(random_bytes(4));
        $owner = PlatformUser::query()->create(['code' => 'PU'.$suffix, 'name' => 'Ownership Test Owner',
            'email' => $suffix.'@ownership.test', 'phone' => '099'.$suffix, 'password' => 'secret123', 'status' => 'active']);
        $this->ownershipFixtureOwnerIds[] = (int) $owner->id;
        $tenant = Tenant::query()->create(['platform_user_id' => $owner->id, 'tenant_code' => 'ot'.$suffix,
            'subdomain' => 'ot'.$suffix, 'name' => 'Ownership Test '.$suffix, 'status' => 'active']);
        $this->ownershipFixtures[$tenant->id] = ['tenant' => $tenant];
        app(TenantContext::class)->set($tenant);

        // Grant no business permissions by default; individual HTTP tests choose their permissions.
        $role = TenantRole::query()->create(['name' => 'Ownership Test '.$suffix, 'is_default' => false, 'permissions' => []]);
        $this->ownershipFixtureRoleIds[] = (int) $role->id;
        $actor = TenantUser::query()->create(['code' => 'TU'.$suffix, 'tenant_id' => $tenant->id,
            'role_id' => $role->id, 'username' => $suffix, 'name' => 'Ownership Test User',
            'nrc' => '12/PaTaNa(N)'.$suffix, 'email' => 'actor'.$suffix.'@ownership.test',
            'phone' => '095'.$suffix, 'password' => 'secret123', 'status' => 'active', 'is_deleted' => false]);

        // Use tenant-owned finance/unit metadata so tests never mutate shared platform records.
        $currency = Currency::query()->create(['tenant_id' => $tenant->id, 'scope_key' => 'tenant:'.$tenant->id,
            'code' => 'MMK', 'name' => 'Test Kyat', 'decimal_precision' => 2, 'rounding_mode' => 'HALF_UP', 'is_active' => true]);
        TenantSetting::query()->create(['tenant_id' => $tenant->id, 'key' => 'timezone', 'value' => 'UTC']);
        TenantSetting::query()->create(['tenant_id' => $tenant->id, 'key' => 'currency_preferences', 'category' => 'finance',
            'default_currency_id' => $currency->id, 'reporting_currency_id' => $currency->id, 'update_key' => 0]);
        $accountType = FinancialAccountTypes::query()->create(['tenant_id' => $tenant->id,
            'code' => 'otcash'.$suffix, 'name' => 'Test Cash', 'is_active' => true]);
        $account = FinancialAccount::query()->create(['tenant_id' => $tenant->id, 'account_type_id' => $accountType->id,
            'currency_id' => $currency->id, 'account_code' => 'OT'.$suffix, 'account_name' => 'Ownership Test Cash',
            'balance' => '1000000.0000', 'is_active' => true, 'is_default' => true, 'is_deleted' => false,
            'allow_negative_balance' => false]);
        $unit = CatalogUnit::query()->create(['tenant_id' => $tenant->id, 'scope_key' => 'tenant:'.$tenant->id,
            'code' => 'otunit'.$suffix, 'name' => 'Test piece', 'is_active' => true, 'is_system' => false]);
        $location = InventoryLocation::query()->create(['tenant_id' => $tenant->id, 'code' => 'OL'.$suffix,
            'name' => 'Ownership Test Shop', 'type' => 'SHOP', 'is_default' => true, 'is_active' => true, 'is_sellable' => true]);

        // Return references for cross-tenant cases and activate the newly created tenant explicitly.
        $fixture = compact('tenant', 'owner', 'role', 'actor', 'currency', 'account', 'unit', 'location');
        $this->ownershipFixtures[$tenant->id] = $fixture;
        $this->activateOwnershipFixture($fixture);
        return $fixture;
    }

    protected function activateOwnershipFixture(array $fixture): void
    {
        // Tests switching tenants must switch both scope and actor, never just a supplied tenant ID.
        $this->ownershipFixture = $fixture;
        app(TenantContext::class)->set($fixture['tenant']);
        Auth::guard('tenantuser')->setUser($fixture['actor']);
    }

    protected function createOwnershipInventoryItem(string $mode = 'QUANTITY', string $name = 'Ownership goods'): InventoryItem
    {
        // An identity fixture is not a receipt: do not manufacture physical stock or Ownership.
        if (! in_array($mode, ['QUANTITY', 'UNIQUE', 'SERIALIZED'], true)) {
            throw new LogicException('Unknown fixture tracking mode.');
        }
        return InventoryItem::query()->create(['tenant_id' => $this->ownershipFixture['tenant']->id,
            'code' => 'OI'.bin2hex(random_bytes(6)), 'name' => $name, 'tracking_mode' => $mode,
            'unit_id' => $this->ownershipFixture['unit']->id, 'quantity_scale' => 1, 'update_key' => 0]);
    }

    protected function createOwnershipPurchase(
        InventoryItem $item,
        string $quantity = '10.000',
        string $unitPrice = '12.34',
        ?string $paid = '123.40',
        string $status = 'CONFIRMED',
    ): array {
        // Persist source facts without calling PurchasingService or the acquisition under test.
        $tenantId = (int) $this->ownershipFixture['tenant']->id;
        if ((int) $item->tenant_id !== $tenantId) {
            throw new LogicException('Purchase fixture item must belong to the active fixture tenant.');
        }
        $person = TenantPerson::query()->create(['tenant_id' => $tenantId, 'name' => 'Ownership Supplier']);
        $supplier = PurchaseSupplier::query()->create(['tenant_id' => $tenantId, 'code' => 'OS'.bin2hex(random_bytes(6)),
            'person_id' => $person->id, 'type' => 'INDIVIDUAL', 'is_active' => true]);
        $order = PurchaseOrder::query()->create(['tenant_id' => $tenantId, 'code' => 'PO'.bin2hex(random_bytes(6)),
            'supplier_id' => $supplier->id, 'status' => $status, 'ordered_at' => '2026-10-15',
            'currency_code' => $this->ownershipFixture['currency']->code, 'created_by' => $this->ownershipFixture['actor']->id]);
        $line = PurchaseOrderLine::query()->create(['tenant_id' => $tenantId, 'code' => 'PL'.bin2hex(random_bytes(6)),
            'purchase_order_id' => $order->id, 'inventory_item_id' => $item->id, 'item_description' => $item->name,
            'tracking_mode' => $item->tracking_mode, 'unit_label' => $this->ownershipFixture['unit']->name,
            'unit_code' => $this->ownershipFixture['unit']->code, 'ordered_quantity' => $quantity, 'unit_price' => $unitPrice]);

        // Null/zero payment builds unpaid/free-source cases without a fake zero-value payment row.
        $payment = null;
        if ($paid !== null && ! preg_match('/\A0+(?:\.0+)?\z/', $paid)) {
            $payment = PurchasePayment::query()->create(['tenant_id' => $tenantId, 'code' => 'PP'.bin2hex(random_bytes(6)),
                'purchase_order_id' => $order->id, 'paid_at' => '2026-10-15', 'amount' => $paid,
                'financial_account_id' => $this->ownershipFixture['account']->id,
                'created_by' => $this->ownershipFixture['actor']->id]);
        }
        return compact('order', 'line', 'payment', 'supplier', 'item');
    }

    protected function receiveOwnershipPurchase(array $purchase, string $quantity, array $identifiers = []): array
    {
        // Seed receipt provenance, then exercise real Inventory receipt; do not acquire/link Ownership here.
        $tenantId = (int) $this->ownershipFixture['tenant']->id;
        if ((int) $purchase['order']->tenant_id !== $tenantId
            || (int) $purchase['line']->purchase_order_id !== (int) $purchase['order']->id
            || (int) $purchase['item']->tenant_id !== $tenantId
            || (int) $purchase['line']->inventory_item_id !== (int) $purchase['item']->id) {
            throw new LogicException('Receipt fixture must use its active tenant purchase source.');
        }
        $receipt = PurchaseReceipt::query()->create(['tenant_id' => $tenantId, 'code' => 'PR'.bin2hex(random_bytes(6)),
            'purchase_order_id' => $purchase['order']->id, 'received_at' => '2026-10-15',
            'created_by' => $this->ownershipFixture['actor']->id]);
        $receiptLine = PurchaseReceiptLine::query()->create(['tenant_id' => $tenantId, 'code' => 'RL'.bin2hex(random_bytes(6)),
            'purchase_receipt_id' => $receipt->id, 'purchase_order_line_id' => $purchase['line']->id,
            'received_quantity' => $quantity, 'inventory_item_id' => $purchase['item']->id,
            'inventory_location_code' => $this->ownershipFixture['location']->code]);
        $inventory = app(InventoryService::class)->receive(new InventoryReceiveRequest(
            locationCode: $this->ownershipFixture['location']->code, quantity: $quantity,
            inventoryItemCode: $purchase['item']->code, serializedItemIdentifiers: $identifiers,
            sourceType: 'PurchaseReceiptLine', sourceCode: $receiptLine->code,
        ));
        $receiptLine->update(['serialized_item_codes' => $inventory['serialized_item_codes']]);
        return compact('receipt', 'receiptLine', 'inventory');
    }

    protected function seedOwnershipLot(array $purchase): array
    {
        // Isolate quantity-operation tests with a known ledger baseline, never as acquisition evidence.
        $tenantId = (int) $this->ownershipFixture['tenant']->id;
        if ((int) $purchase['order']->tenant_id !== $tenantId
            || (int) $purchase['line']->purchase_order_id !== (int) $purchase['order']->id
            || (int) $purchase['item']->tenant_id !== $tenantId
            || (int) $purchase['line']->inventory_item_id !== (int) $purchase['item']->id) {
            throw new LogicException('Ownership baseline must use one active tenant purchase/item.');
        }
        $ownedItem = OwnedItem::query()->firstOrCreate(['tenant_id' => $tenantId,
            'inventory_item_id' => $purchase['item']->id], ['code' => 'OW'.bin2hex(random_bytes(6)),
            'lifecycle_status' => 'ACTIVE', 'created_by' => $this->ownershipFixture['actor']->id]);
        $lot = AcquisitionLot::query()->create(['tenant_id' => $tenantId, 'code' => 'AL'.bin2hex(random_bytes(6)),
            'owned_item_id' => $ownedItem->id, 'source_module' => 'PURCHASING', 'source_type' => 'PurchaseOrderLine',
            'source_code' => $purchase['line']->code, 'acquired_at' => '2026-10-15',
            'acquired_quantity' => $purchase['line']->ordered_quantity, 'unit_cost_basis' => $purchase['line']->unit_price,
            'currency_code' => $purchase['order']->currency_code, 'created_by' => $this->ownershipFixture['actor']->id]);
        $movement = OwnershipMovement::query()->create(['tenant_id' => $tenantId, 'code' => 'OM'.bin2hex(random_bytes(6)),
            'owned_item_id' => $ownedItem->id, 'acquisition_lot_id' => $lot->id, 'movement_type' => 'ACQUISITION',
            'quantity' => $purchase['line']->ordered_quantity, 'owned_delta' => $purchase['line']->ordered_quantity,
            'reserved_delta' => '0.000', 'pledged_delta' => '0.000', 'source_module' => 'PURCHASING',
            'source_type' => 'PurchaseOrderLine', 'source_code' => $purchase['line']->code,
            'occurred_at' => '2026-10-15 12:00:00', 'created_by' => $this->ownershipFixture['actor']->id]);
        return compact('ownedItem', 'lot', 'movement');
    }
}
