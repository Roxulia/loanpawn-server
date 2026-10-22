<?php

namespace App\Services\TenantModule\Ownership;

use App\DTOs\Ownership\Internal\ResponseObjects\OwnershipFifoResult;
use App\Exceptions\InvalidTenantRequest;
use App\Repository\OwnershipRepository;
use App\Services\BaseTenantService;
use App\Services\TenantModule\InventoryService;
use App\Support\InventoryQuantity;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Shared allocation only: caller applies Sale/pledge effects before releasing this transaction. */
class FifoService extends BaseTenantService
{
    public function __construct(private OwnershipRepository $repository, private InventoryService $inventoryService,
        private LookupService $lookup, private BalanceService $balances) {}

    public function allocate(string $itemCode, mixed $quantity, array $serials = []): OwnershipFifoResult
    {
        // Item resolution and missing-resource errors belong to the appropriate module services.
        if (DB::transactionLevel() === 0) throw new LogicException('Ownership FIFO allocation requires an active transaction.');
        $item = $this->inventoryService->findForOwnershipByCode($itemCode);
        $requested = InventoryQuantity::parse($quantity, $item->tracking_mode);
        $serials = $this->validateSerials($item->tracking_mode, $requested, $serials);
        $owner = $this->lookup->byInventoryId((int) $item->id, true);
        $tenant = $this->resolveCurrentTenantId();
        $lots = $this->repository->lockedLots($tenant, (int) $owner->id);
        $balances = $this->balances->locked((int) $owner->id, $lots);
        $links = $this->repository->receiptLinks($tenant, $lots->pluck('id')->all(), true)->groupBy('acquisition_lot_id');
        $members = $this->repository->currentMemberships($tenant, $lots->pluck('id')->all(), true, ownedItemId: (int) $owner->id);
        $held = $this->repository->pieceCommitments($tenant, $members->pluck('id')->all(), true)->keyBy('ownership_piece_membership_id');
        $stock = $this->inventoryService->ownershipStock($item, $serials);

        // Received backing and aggregate availability must both cover each selected cost layer.
        $capacity = [];
        foreach ($lots as $lot) {
            $balance = $balances[$lot->id];
            $received = 0;
            foreach ($links->get($lot->id, collect()) as $link) $received += InventoryQuantity::parseBalance($link->remaining_owned_quantity);
            $capacity[$lot->id] = max(0, min($balance['available'], $received - $balance['reserved'] - $balance['pledged']));
        }
        $ordered = $lots->sortBy([['acquired_at', 'asc'], ['id', 'asc']]);
        $rows = $item->tracking_mode === 'SERIALIZED'
            ? $this->serialized($ordered, $capacity, $stock, $members, $held, $requested)
            : $this->quantity($ordered, $capacity, $stock->locations, $requested);
        return new OwnershipFifoResult($item->code, $owner->code, InventoryQuantity::format($requested), $rows);
    }

    private function validateSerials(string $mode, int $quantity, array $serials): array
    {
        // Explicit identifiers choose pieces; duplicates are never silently deduplicated or replaced.
        if ($mode !== 'SERIALIZED') {
            if ($serials !== []) throw new InvalidTenantRequest(__('inventory.unit_selection_invalid'));
            return [];
        }
        foreach ($serials as &$serial) {
            if (! is_string($serial) || trim($serial) === '' || mb_strlen(trim($serial)) > 120) $this->invalidPiece();
            $serial = trim($serial);
        }
        if (count($serials) * 1000 !== $quantity || count(array_unique($serials)) !== count($serials)) $this->invalidPiece();
        return $serials;
    }

    private function serialized($lots, array $capacity, $stock, $members, $held, int $requested): array
    {
        // Every requested serial must resolve exactly once to an eligible, currently owned membership.
        if (count($stock->pieces) * 1000 !== $requested) $this->invalidPiece();
        $membership = $members->keyBy('inventory_serialized_item_id');
        $pieces = [];
        foreach ($stock->pieces as $piece) {
            $member = $membership->get($piece['id']);
            if (! $piece['available'] || $member === null || $held->has($member->id)) $this->invalidPiece();
            $pieces[$member->acquisition_lot_id][$piece['location_id']][] = $piece['code'];
        }
        $rows = [];
        foreach ($lots as $lot) {
            foreach ($stock->locations as $location) {
                $codes = $pieces[$lot->id][$location['id']] ?? [];
                if ($codes === []) continue;
                $amount = count($codes) * 1000;
                if ($amount > $capacity[$lot->id]) $this->invalidPiece();
                $capacity[$lot->id] -= $amount;
                $rows[] = $this->row($lot, $location['code'], $amount, $codes);
            }
        }
        return $rows;
    }

    private function quantity($lots, array $capacity, array $locations, int $remaining): array
    {
        // Fungible stock shares physical location capacity while retaining its own received lot backing.
        $rows = [];
        foreach ($lots as $lot) {
            foreach ($locations as &$location) {
                $amount = min($remaining, $capacity[$lot->id], $location['available']);
                if ($amount <= 0) continue;
                $rows[] = $this->row($lot, $location['code'], $amount, []);
                $capacity[$lot->id] -= $amount;
                $location['available'] -= $amount;
                $remaining -= $amount;
                if ($remaining === 0) return $rows;
            }
            unset($location);
        }
        throw new InvalidTenantRequest(__('ownership.insufficient_lot_balance'));
    }

    private function row($lot, string $location, int $quantity, array $codes): array
    {
        // Copy historical cost/currency without averaging across lots or introducing monetary totals.
        return ['lot_code' => $lot->code, 'location_code' => $location, 'quantity' => InventoryQuantity::format($quantity),
            'serialized_item_codes' => $codes, 'unit_cost_basis' => $lot->unit_cost_basis, 'currency_code' => $lot->currency_code];
    }

    private function invalidPiece(): never
    {
        throw new InvalidTenantRequest(__('ownership.piece_selection_invalid'));
    }
}
