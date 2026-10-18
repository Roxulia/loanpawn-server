<?php

namespace App\DataObjects\RequestObjects;

/**
 * Describes a trusted caller's reservation of physical stock for a business source.
 * Availability, sellability, source binding, and unit membership are service responsibilities.
 */
final class InventoryReservationRequest extends InventoryStockCommand
{
    /** @var list<string> */
    public array $inventoryUnitCodes;

    public function __construct(
        public string $inventoryItemCode,
        public string $locationCode,
        string|int|float $quantity,
        public string $sourceType,
        public string $sourceCode,
        array $inventoryUnitCodes = [],
        public ?string $idempotencyKey = null,
    ) {
        // Normalize the command without allowing a caller-selected validation bypass.
        parent::__construct($quantity);
        $this->inventoryItemCode = trim($inventoryItemCode);
        $this->locationCode = trim($locationCode);
        $this->sourceType = trim($sourceType);
        $this->sourceCode = trim($sourceCode);
        $this->inventoryUnitCodes = self::normalizeUnitCodes($inventoryUnitCodes);
    }
}
