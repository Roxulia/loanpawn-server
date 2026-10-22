<?php

namespace App\DTOs\Ownership\Internal\RequestObjects;

use App\DataObjects\RequestObjects\InventoryStockCommand;

/**
 * Describes an internal pledge, release, or forfeiture against one owned lot.
 * Carries the exact quantity, collateral source reference, selected pieces, and replay key.
 * New pledges can carry typed resolver output; existing-source operations resolve
 * their persisted commitments in the service instead of treating new input as approval.
 * Source matching, currency checks, authorization, and lender custody are not performed here.
 */
final class OwnershipPledgeRequest extends InventoryStockCommand
{
    /** @var list<string> Exact piece codes, e.g. ["PIECE-A"]; empty for quantity-tracked goods. */
    public array $serializedItemCodes;

    public function __construct(
        public string $ownedItemCode,
        public string $lotCode,
        string|int|float $quantity,
        public string $sourceType,
        public string $sourceCode,
        array $serializedItemCodes = [],
        ?string $idempotencyKey = null,
        public ?OwnershipPledgeSource $sourceContext = null,
    ) {
        // Preserve source identity and selection while reusing Inventory's exact quantity helpers.
        parent::__construct($quantity);
        $this->ownedItemCode = trim($ownedItemCode);
        $this->lotCode = trim($lotCode);
        $this->sourceType = trim($sourceType);
        $this->sourceCode = trim($sourceCode);
        $this->serializedItemCodes = self::normalizeSerializedItemCodes($serializedItemCodes);
        $this->idempotencyKey = $idempotencyKey;
    }
}
