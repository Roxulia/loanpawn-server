<?php

namespace App\DTOs\Ownership\Internal\RequestObjects;

use App\DataObjects\RequestObjects\InventoryStockCommand;

/**
 * Describes an internal sale reservation, release, or consumption against one owned lot.
 * Identifies the sale line whose commitment is affected, exact quantity such as "2.000",
 * selected piece codes, and the caller's replay key.
 * Services resolve the real sale line, permissions, eligibility, and current commitment;
 * this command contains no public factory or trusted bypass flag.
 */
final class OwnershipSaleRequest extends InventoryStockCommand
{
    /** @var list<string> Exact piece codes in caller selection order; empty for quantity-tracked goods. */
    public array $serializedItemCodes;

    public function __construct(
        public string $ownedItemCode,
        public string $lotCode,
        string|int|float $quantity,
        public string $saleLineCode,
        array $serializedItemCodes = [],
        ?string $idempotencyKey = null,
    ) {
        // Reuse exact quantity and piece-reference parsing without resolving business authority here.
        parent::__construct($quantity);
        $this->ownedItemCode = trim($ownedItemCode);
        $this->lotCode = trim($lotCode);
        $this->saleLineCode = trim($saleLineCode);
        $this->serializedItemCodes = self::normalizeSerializedItemCodes($serializedItemCodes);
        $this->idempotencyKey = $idempotencyKey;
    }
}
