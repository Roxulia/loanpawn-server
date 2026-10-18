<?php

namespace App\DataObjects\RequestObjects;

use App\Enums\InventoryIssueContext;

/**
 * Describes an internal caller's issue with explicit provenance and reservation.
 * Constructed by trusted services, not hydrated from arbitrary HTTP input.
 * Source metadata identifies the caller; it never grants permission or bypasses checks.
 */
final class InventoryIssueRequest extends InventoryStockCommand
{
    /** @var list<string> */
    public array $serializedItemCodes;

    public function __construct(
        public string $inventoryItemCode,
        public string $locationCode,
        string|int|float $quantity,
        public string $sourceType,
        public string $sourceCode,
        public InventoryIssueContext $operationContext,
        public ?string $reservationCode = null,
        array $serializedItemCodes = [],
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
    ) {
        // Preserve caller provenance and explicit reservation references for service binding checks.
        parent::__construct($quantity);
        $this->inventoryItemCode = trim($inventoryItemCode);
        $this->locationCode = trim($locationCode);
        $this->sourceType = trim($sourceType);
        $this->sourceCode = trim($sourceCode);
        $this->reservationCode = $reservationCode === null ? null : trim($reservationCode);
        $this->serializedItemCodes = self::normalizeSerializedItemCodes($serializedItemCodes);
    }

    public function toArray(): array
    {
        // Serialize the backed context value for deterministic audit/idempotency payloads.
        $data = parent::toArray();
        $data['operation_context'] = $this->operationContext->value;

        return $data;
    }
}
