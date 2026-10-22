<?php

namespace App\Services\TenantModule\Ownership;

use App\DTOs\Ownership\Public\ResponseObjects\OwnershipResult;
use App\Exceptions\{InvalidTenantRequest, TenantNotFound};
use App\DataObjects\RequestObjects\InventoryReceiveRequest;
use App\Repository\OwnershipRepository;
use App\Services\BaseTenantService;
use App\Services\TenantModule\{InventoryService, TenantIdempotencyService, TenantCurrencyService};
use App\Services\TableIdGenerationService;
use App\Support\{InventoryQuantity, OwnershipCost};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{Auth, DB};

/** Payment-qualified acquisition prerequisite for real paginated-read integration fixtures. */
class AcquisitionService extends BaseTenantService
{
    public function __construct(private OwnershipRepository $repository, private PurchaseSourceService $purchases,
        private InventoryService $inventoryService, private TenantIdempotencyService $idempotency,
        private TenantCurrencyService $currencies, private TableIdGenerationService $codes, private BalanceService $balances) {}

    public function purchaseLine(string $code, ?string $key = null): OwnershipResult
    {
        // Source qualification, acquisition and internal replay completion are one database transaction.
        return DB::transaction(function () use ($code, $key): OwnershipResult {
            $record = $this->idempotency->reserveOptional('ownership.purchase_acquisition', $key, ['purchase_line_code' => $code]);
            if ($record !== null && $this->idempotency->isReplay($record)) {
                $data = $record->response_body;
                return new OwnershipResult($data['owned_item_code'], $data['update_key'], $data['lot_codes'],
                    $data['movement_codes'], $data['receipt_link_codes'], $data['serialized_item_codes']);
            }
            [$line, $date] = $this->purchases->qualifiedLine($code);
            $tenant = $this->resolveCurrentTenantId();
            if ($line->inventory_item_id === null) {
                $item = $this->inventoryService->createOwnershipIdentity(new InventoryReceiveRequest(
                    locationCode: '', quantity: $line->ordered_quantity, name: $line->item_description,
                    trackingMode: $line->tracking_mode, unitCode: $line->unit_code,
                    catalogItemCode: $line->catalogItem?->business_code));
                $this->purchases->attachIdentity($line, (int) $item->id);
            } else {
                try {
                    $item = $this->inventoryService->findForOwnershipById((int) $line->inventory_item_id);
                } catch (TenantNotFound) {
                    throw new InvalidTenantRequest(__('ownership.purchase_source_invalid'));
                }
                if ($line->tracking_mode !== $item->tracking_mode || $line->unit_code !== $item->unit->code) {
                    throw new InvalidTenantRequest(__('ownership.purchase_source_invalid'));
                }
            }
            $quantity = InventoryQuantity::format(InventoryQuantity::parse($line->ordered_quantity, $item->tracking_mode));
            $this->currencies->findActiveVisibleByCodeForTenant($tenant, $line->order->currency_code);
            // A deleted payment currency cannot be replaced by another currency with the same code.
            foreach ($this->purchases->currencyIds((int) $line->purchase_order_id) as $currencyId) {
                $currency = $this->currencies->findActiveVisibleForTenant($tenant, (int) $currencyId);
                if ($currency->code !== $line->order->currency_code) throw new InvalidTenantRequest(__('ownership.purchase_source_invalid'));
            }
            if ($this->repository->findBySourceCode($tenant, 'PURCHASING', 'PurchaseOrderLine', $code) !== null) {
                throw new InvalidTenantRequest(__('ownership.source_already_recorded'));
            }
            $owner = $this->repository->ownedItemForInventory($tenant, (int) $item->id, true);
            if ($owner !== null && $item->tracking_mode === 'UNIQUE') {
                $lots = $this->repository->lockedLots($tenant, (int) $owner->id);
                $current = $this->balances->locked((int) $owner->id, $lots);
                if (array_sum(array_column($current, 'owned')) !== 0) throw new InvalidTenantRequest(__('ownership.unique_already_owned'));
            }
            $owner ??= $this->repository->createOwnedItem($tenant, ['code' => $this->codes->generate('owned_items', CarbonImmutable::now()),
                'inventory_item_id' => $item->id, 'catalog_item_id' => $item->catalog_item_id, 'lifecycle_status' => 'ACTIVE',
                'created_by' => Auth::guard('tenantuser')->id()]);
            $lot = $this->repository->createLot($tenant, ['code' => $this->codes->generate('acquisition_lots', CarbonImmutable::now()),
                'owned_item_id' => $owner->id, 'source_module' => 'PURCHASING', 'source_type' => 'PurchaseOrderLine', 'source_code' => $code,
                'acquired_at' => $date, 'acquired_quantity' => $quantity, 'unit_cost_basis' => OwnershipCost::normalize($line->unit_price),
                'currency_code' => $line->order->currency_code, 'created_by' => Auth::guard('tenantuser')->id()]);
            $movement = $this->repository->createMovement($tenant, ['code' => $this->codes->generate('ownership_movements', CarbonImmutable::now()),
                'owned_item_id' => $owner->id, 'acquisition_lot_id' => $lot->id, 'movement_type' => 'ACQUISITION', 'quantity' => $quantity,
                'owned_delta' => $quantity, 'reserved_delta' => '0.000', 'pledged_delta' => '0.000', 'source_module' => 'PURCHASING',
                'source_type' => 'PurchaseOrderLine', 'source_code' => $code, 'idempotency_record_id' => $record?->id,
                'created_by' => Auth::guard('tenantuser')->id(), 'occurred_at' => CarbonImmutable::now('UTC')]);
            $result = new OwnershipResult($owner->code, $this->repository->advanceVersion($owner), [$lot->code], [$movement->code]);
            if ($record !== null) $this->idempotency->markCompleted($record, 201, $result->toArray());
            return $result;
        });
    }
}
