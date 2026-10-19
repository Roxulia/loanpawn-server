<?php

namespace App\DataObjects\RequestObjects;

/**
 * Carries a trusted sale-return line's physical receipt without recreating identities.
 * SaleReturnLine provenance and the reason are fixed by the Inventory service.
 */
final class InventorySaleReturnRequest extends InventoryStockCommand
{
    /** @var list<string> */
    public array $serializedItemCodes;

    public function __construct(
        public string $inventoryItemCode,
        public string $locationCode,
        string|int|float $quantity,
        public string $sourceCode,
        array $serializedItemCodes = [],
        public ?string $idempotencyKey = null,
        public ?bool $expectedSellable = null,
    ) {
        // Normalize the physical identities while preserving the caller's canonical return code.
        parent::__construct($quantity);
        $this->inventoryItemCode = trim($inventoryItemCode);
        $this->locationCode = trim($locationCode);
        $this->sourceCode = trim($sourceCode);
        $this->serializedItemCodes = self::normalizeSerializedItemCodes($serializedItemCodes);
    }
}
