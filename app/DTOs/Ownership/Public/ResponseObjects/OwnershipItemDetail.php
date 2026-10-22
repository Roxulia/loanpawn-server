<?php

namespace App\DTOs\Ownership\Public\ResponseObjects;

use App\DataObjects\ResponseObjects\InventoryLocationBalance;

use App\DataObjects\BaseDataObject;
use InvalidArgumentException;

/**
 * Combines the item summary with existing Inventory location projections and receipt-link state.
 * Lots, movement history, and serialized pieces use their own pages rather than embedded collections.
 * The service supplies resolved DTOs; this object never loads database records or photo files.
 */
final class OwnershipItemDetail extends BaseDataObject
{
    /** @var list<InventoryLocationBalance> */
    public array $locations;

    public function __construct(
        public OwnershipItemSummary $summary,
        array $locations = [],
        public string $receiptLinkState = 'PENDING',
    ) {
        // Require existing typed Inventory projections, preventing raw model/array serialization.
        foreach ($locations as $location) {
            if (! $location instanceof InventoryLocationBalance) {
                throw new InvalidArgumentException('Expected InventoryLocationBalance entries.');
            }
        }
        $this->locations = array_values($locations);
    }

    public function toArray(): array
    {
        // Preserve flat summary fields and append only the bounded detail-specific projections.
        return array_merge($this->summary->toArray(), [
            'locations' => array_map(static fn (InventoryLocationBalance $location): array => $location->toArray(), $this->locations),
            'receipt_link_state' => $this->receiptLinkState,
        ]);
    }
}
