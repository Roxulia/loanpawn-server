<?php

namespace App\Services\TenantModule\Ownership;

use App\DTOs\Ownership\Internal\ResponseObjects\OwnershipReturnSelection;
use App\Exceptions\InvalidTenantRequest;
use App\Models\OwnershipModule\{AcquisitionLot, OwnedItem};
use App\Models\PurchasingModule\PurchaseReturnLine;
use App\Models\SalesModule\SaleReturnLine;
use App\Repository\OwnershipRepository;
use App\Services\BaseTenantService;
use App\Services\TenantModule\InventoryService;
use App\Support\InventoryQuantity;
use Illuminate\Support\Collection;

/** Validates existing internal return sources without activating public or financial return workflows. */
class ReturnValidationService extends BaseTenantService
{
    public function __construct(private OwnershipRepository $repository,
        private PurchaseSourceService $purchases, private SaleSourceService $sales, private InventoryService $inventoryService) {}

    public function resolveReturnSource(string $type, string $code): PurchaseReturnLine|SaleReturnLine
    {
        // Source locks precede Inventory and Ownership anchors.
        return match ($type) {
            'PurchaseReturnLine' => $this->purchases->returnLine($code),
            'SaleReturnLine' => $this->sales->returnLine($code),
            default => throw new InvalidTenantRequest(__('ownership.commitment_source_invalid')),
        };
    }

    public function validateReturnEligibility(PurchaseReturnLine|SaleReturnLine $source, OwnedItem $owner,
        AcquisitionLot $lot, string $type, mixed $quantity, string $trackingMode): OwnershipReturnSelection
    {
        // Separate source provenance, remaining allowance and exact-piece rules into reviewable steps.
        $tenant = $this->resolveCurrentTenantId();
        $this->validateReturnTenantAndLot($source, $owner, $lot, $tenant);
        $amount = InventoryQuantity::parse($quantity, $trackingMode);
        [$limit, $codes, $original] = $type === 'PurchaseReturnLine'
            ? $this->validatePurchaseReturnProvenance($source, $owner, $lot, $trackingMode, $tenant)
            : $this->validateSaleReturnProvenance($source, $owner, $lot, $trackingMode, $tenant);
        $this->validateRemainingReturnAllowance($source, $owner, $type, $amount, $limit, $tenant);
        $this->validateReturnedPieceCodes($trackingMode, $limit, $codes, $original);
        [$members, $pieces] = $trackingMode === 'SERIALIZED'
            ? $this->selectValidatedReturnMemberships($owner, $lot, $type, $codes, $tenant)
            : [collect(), []];
        return new OwnershipReturnSelection($tenant, (int) $owner->id, (int) $lot->id, $type, $amount, $codes, $members, $pieces);
    }

    private function validateReturnTenantAndLot($source, OwnedItem $owner, AcquisitionLot $lot, int $tenant): void
    {
        // A valid code cannot borrow another tenant's owner or another item's acquisition lot.
        if ((int) $source->tenant_id !== $tenant || (int) $owner->tenant_id !== $tenant
            || (int) $lot->tenant_id !== $tenant || (int) $lot->owned_item_id !== (int) $owner->id) $this->rejectInvalidReturnSource();
    }

    private function validatePurchaseReturnProvenance(PurchaseReturnLine $source, OwnedItem $owner,
        AcquisitionLot $lot, string $mode, int $tenant): array
    {
        // Preserve both current purchase-line sources and existing receipt-source compatibility.
        $receipt = $source->receiptLine;
        $parent = $source->returnRecord;
        if ($receipt === null || $parent === null || $receipt->orderLine === null
            || (int) $receipt->tenant_id !== $tenant || (int) $parent->tenant_id !== $tenant
            || (int) $receipt->orderLine->tenant_id !== $tenant
            || (int) $receipt->inventory_item_id !== (int) $owner->inventory_item_id
            || (int) $parent->purchase_order_id !== (int) $receipt->orderLine->purchase_order_id
            || $lot->source_module !== 'PURCHASING') $this->rejectInvalidReturnSource();
        $matchesPurchase = $lot->source_type === 'PurchaseOrderLine' && $lot->source_code === $receipt->orderLine->code;
        $matchesReceipt = $lot->source_type === 'PurchaseReceiptLine' && $lot->source_code === $receipt->code;
        if (! $matchesPurchase && ! $matchesReceipt) $this->rejectInvalidReturnSource();
        return [InventoryQuantity::parse($source->returned_quantity, $mode),
            $source->serialized_item_codes ?? [], $receipt->serialized_item_codes ?? []];
    }

    private function validateSaleReturnProvenance(SaleReturnLine $source, OwnedItem $owner,
        AcquisitionLot $lot, string $mode, int $tenant): array
    {
        // Restore only against the original order, allocation, Inventory identity and acquisition lot.
        $allocation = $source->allocation;
        $parent = $source->returnRecord;
        if ($allocation === null || $allocation->line === null || (int) $allocation->tenant_id !== $tenant
            || $parent === null || (int) $parent->tenant_id !== $tenant
            || (int) $parent->sales_order_id !== (int) $allocation->line->sales_order_id
            || (int) $allocation->line->tenant_id !== $tenant || (int) $allocation->acquisition_lot_id !== (int) $lot->id
            || (int) $allocation->line->owned_item_id !== (int) $owner->id
            || (int) $allocation->line->inventory_item_id !== (int) $owner->inventory_item_id) $this->rejectInvalidReturnSource();
        $limit = InventoryQuantity::parse($source->quantity, $mode);
        if ($limit > InventoryQuantity::parse($allocation->quantity, $mode)) $this->rejectInvalidReturnSource();
        $codes = $source->serialized_item_codes ?? [];
        if (is_string($codes)) $codes = json_decode($codes, true, 512, JSON_THROW_ON_ERROR);
        return [$limit, $codes, $allocation->serialized_item_codes ?? []];
    }

    private function validateRemainingReturnAllowance($source, OwnedItem $owner, string $type, int $amount, int $limit, int $tenant): void
    {
        // A different retry key cannot reverse the same return source twice.
        $used = 0;
        foreach ($this->repository->sourceMovements($tenant, (int) $owner->id, $type, $source->code) as $movement) {
            $used += InventoryQuantity::parse($movement->quantity);
        }
        if ($amount + $used > $limit) $this->rejectInvalidReturnSource();
    }

    private function validateReturnedPieceCodes(string $mode, int $limit, mixed $codes, array $original): void
    {
        // Non-serialized stock carries no pieces; serialized sources identify distinct original units.
        if ($mode !== 'SERIALIZED') {
            if ($codes !== []) $this->rejectInvalidReturnSource();
            return;
        }
        if (! is_array($codes) || count($codes) * 1000 !== $limit
            || count(array_unique($codes)) !== count($codes) || array_diff($codes, $original) !== []) $this->rejectInvalidReturnSource();
    }

    private function selectValidatedReturnMemberships(OwnedItem $owner, AcquisitionLot $lot, string $type, array $codes, int $tenant): array
    {
        // Read custody and current membership once; the purchase-return mutation reuses this selection.
        $lots = $this->repository->lockedLots($tenant, (int) $owner->id);
        $stock = $this->inventoryService->ownershipStock($owner->inventoryItem, pieceCodes: $codes);
        if (count($stock->pieces) !== count($codes)) $this->rejectInvalidReturnSource();
        $members = $this->repository->currentMemberships($tenant, $lots->pluck('id')->all(), true,
            ownedItemId: (int) $owner->id, pieceIds: array_column($stock->pieces, 'id'));
        if ($type === 'SaleReturnLine' && $members->isNotEmpty()) $this->rejectInvalidReturnSource();
        if ($type === 'PurchaseReturnLine' && ($members->count() !== count($codes)
            || $members->contains(fn ($member) => (int) $member->acquisition_lot_id !== (int) $lot->id))) $this->rejectInvalidReturnSource();
        return [$members->keyBy('id'), $stock->pieces];
    }

    private function rejectInvalidReturnSource(): never
    {
        // Keep the same localized failure contract across both internal source adapters.
        throw new InvalidTenantRequest(__('ownership.commitment_source_invalid'));
    }
}
