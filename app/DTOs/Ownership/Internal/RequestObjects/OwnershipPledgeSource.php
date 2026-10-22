<?php

namespace App\DTOs\Ownership\Internal\RequestObjects;

use App\DataObjects\RequestObjects\InventoryStockCommand;

/**
 * Carries an internal collateral resolver's expected tenant, source, item, lot,
 * exact quantity, currency, and selected serialized pieces.
 * Existing Part 2 fixtures use it to identify collateral without creating fake loan tables.
 * It does not prove lender authorization, settlement, or physical handover; those
 * remain the owning business workflow's responsibility. No public input factory is provided.
 */
final class OwnershipPledgeSource extends InventoryStockCommand
{
    /** @var list<string> Resolved piece selection; source association is verified by the service. */
    public array $serializedItemCodes;

    public function __construct(
        public int $tenantId,
        public string $sourceType,
        public string $sourceCode,
        public string $ownedItemCode,
        public string $lotCode,
        string|int|float $quantity,
        public string $currencyCode,
        array $serializedItemCodes = [],
    ) {
        // Normalize resolver values without claiming that constructing a DTO grants authority.
        parent::__construct($quantity);
        $this->sourceType = trim($sourceType);
        $this->sourceCode = trim($sourceCode);
        $this->ownedItemCode = trim($ownedItemCode);
        $this->lotCode = trim($lotCode);
        $this->currencyCode = strtoupper(trim($currencyCode));
        $this->serializedItemCodes = self::normalizeSerializedItemCodes($serializedItemCodes);
    }
}
