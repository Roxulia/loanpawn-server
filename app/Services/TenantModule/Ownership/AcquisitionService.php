<?php

namespace App\Services\TenantModule\Ownership;

use App\DTOs\Ownership\Internal\ResponseObjects\{OwnershipAcquisitionValues, OwnershipMutationResult};
use App\DTOs\Ownership\Public\ResponseObjects\OwnershipResult;
use App\DataObjects\RequestObjects\InventoryReceiveRequest;
use App\Exceptions\{InvalidTenantRequest, TenantNotFound};
use App\Models\InventoryModule\InventoryItem;
use App\Models\OwnershipModule\{AcquisitionLot, OwnedItem};
use App\Models\PurchasingModule\PurchaseOrderLine;
use App\Repository\OwnershipRepository;
use App\Services\BaseTenantService;
use App\Services\TableIdGenerationService;
use App\Services\TenantModule\InventoryService;
use App\Support\OwnershipQuantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;

/** Paid-source acquisition creates entitlement and immutable cost history independently of receipt. */
class AcquisitionService extends BaseTenantService
{
    public function __construct(private OwnershipRepository $repository, private PurchaseSourceService $purchases,
        private InventoryService $inventoryService, private MovementService $movements, private OperationService $operations,
        private AcquisitionValidationService $validation, private TableIdGenerationService $codes) {}

    public function acquirePaidPurchaseLine(string $code, ?string $key = null): OwnershipResult
    {
        // Read the orchestration in business order; helpers own only their clearly named step.
        return $this->operations->runAtomicOwnershipOperation('ownership.purchase_acquisition', $key, ['purchase_line_code' => $code],
            function (?int $recordId) use ($code): OwnershipMutationResult {
                [$line, $date] = $this->purchases->qualifiedLine($code);
                $item = $this->resolvePurchaseInventoryIdentity($line);
                $values = $this->validation->validatePurchaseAcquisition($line, $item);
                $owner = $this->lockPurchaseOwnershipAggregate($item, $code);
                $lot = $this->createPaidAcquisitionLot($owner, $line, $values, $date);
                return $this->recordPaidAcquisitionMovement($owner, $lot, $values->quantity, $recordId);
            }, 201, 'ownership.acquired');
    }

    private function resolvePurchaseInventoryIdentity(PurchaseOrderLine $line): InventoryItem
    {
        // A missing identity is created without physical stock; existing identities use their shared item anchor.
        if ($line->inventory_item_id === null) {
            $item = $this->inventoryService->createOwnershipIdentity(new InventoryReceiveRequest(
                locationCode: '', quantity: $line->ordered_quantity, name: $line->item_description,
                trackingMode: $line->tracking_mode, unitCode: $line->unit_code, catalogItemCode: $line->catalogItem?->business_code));
            $this->purchases->attachIdentity($line, (int) $item->id);
            return $item;
        }
        try {
            return $this->inventoryService->findForOwnershipById((int) $line->inventory_item_id);
        } catch (TenantNotFound) {
            throw new InvalidTenantRequest(__('ownership.purchase_source_invalid'));
        }
    }

    private function lockPurchaseOwnershipAggregate(InventoryItem $item, string $sourceCode): OwnedItem
    {
        // The Inventory anchor serializes first acquisition even before an aggregate exists.
        $tenant = $this->resolveCurrentTenantId();
        $owner = $this->repository->ownedItemForInventory($tenant, (int) $item->id, true);
        if ($this->repository->findBySourceCode($tenant, 'PURCHASING', 'PurchaseOrderLine', $sourceCode, true) !== null) {
            throw new InvalidTenantRequest(__('ownership.source_already_recorded'));
        }
        if ($owner !== null && $item->tracking_mode === 'UNIQUE'
            && OwnershipQuantity::compare($this->repository->lockedOwnedQuantity($tenant, (int) $owner->id), '0.000') !== 0) {
            throw new InvalidTenantRequest(__('ownership.unique_already_owned'));
        }
        return $owner ?? $this->repository->createOwnedItem($tenant, [
            'code' => $this->codes->generate('owned_items', CarbonImmutable::now('UTC')),
            'inventory_item_id' => $item->id, 'catalog_item_id' => $item->catalog_item_id,
            'lifecycle_status' => 'ACTIVE', 'created_by' => Auth::guard('tenantuser')->id()]);
    }

    private function createPaidAcquisitionLot(OwnedItem $owner, PurchaseOrderLine $line, OwnershipAcquisitionValues $values, string $date): AcquisitionLot
    {
        // Every valid new purchase retains a new immutable source/cost snapshot, including reacquisition.
        return $this->repository->createLot($this->resolveCurrentTenantId(), [
            'code' => $this->codes->generate('acquisition_lots', CarbonImmutable::now('UTC')), 'owned_item_id' => $owner->id,
            'source_module' => 'PURCHASING', 'source_type' => 'PurchaseOrderLine', 'source_code' => $line->code,
            'acquired_at' => $date, 'acquired_quantity' => $values->quantity, 'unit_cost_basis' => $values->unitCost,
            'currency_code' => $line->order->currency_code, 'created_by' => Auth::guard('tenantuser')->id()]);
    }

    private function recordPaidAcquisitionMovement(OwnedItem $owner, AcquisitionLot $lot, string $quantity, ?int $recordId): OwnershipMutationResult
    {
        // One exact acquisition movement; receipt linking and financial postings belong to their own operations.
        $movement = $this->movements->appendOwnershipMovement([
            'code' => $this->codes->generate('ownership_movements', CarbonImmutable::now('UTC')),
            'owned_item_id' => $owner->id, 'acquisition_lot_id' => $lot->id, 'movement_type' => 'ACQUISITION', 'quantity' => $quantity,
            'owned_delta' => $quantity, 'reserved_delta' => '0.000', 'pledged_delta' => '0.000',
            'source_module' => 'PURCHASING', 'source_type' => 'PurchaseOrderLine', 'source_code' => $lot->source_code,
            'idempotency_record_id' => $recordId]);
        return new OwnershipMutationResult(new OwnershipResult($owner->code, $this->repository->advanceVersion($owner), [$lot->code], [$movement->code]), $owner);
    }
}
