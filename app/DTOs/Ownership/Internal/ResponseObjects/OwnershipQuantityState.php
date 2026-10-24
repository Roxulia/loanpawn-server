<?php

namespace App\DTOs\Ownership\Internal\ResponseObjects;

use App\Models\InventoryModule\InventoryItem;
use App\Models\OwnershipModule\{AcquisitionLot, OwnedItem, OwnershipCommitment};
use App\Models\SalesModule\SaleOrderLine;
use Illuminate\Support\Collection;

/** Internal locked records for one quantity action; never serialized as a public response. */
final readonly class OwnershipQuantityState
{
    public function __construct(
        public int $tenantId,
        public OwnedItem $owner,
        public InventoryItem $item,
        public AcquisitionLot $lot,
        public int $amount,
        public string $quantity,
        public array $balance,
        public Collection $links,
        public Collection $members,
        public Collection $commitments,
        public ?OwnershipCommitment $commitment,
        public Collection $pieceHolds,
        public ?SaleOrderLine $saleLine,
        public string $kind,
        public string $sourceType,
        public string $sourceCode,
    ) {}
}
