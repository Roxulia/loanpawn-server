<?php

namespace App\DataObjects\RequestObjects;

use App\Enums\InventoryIssueContext;

/**
 * Describes an internal caller's issue with explicit provenance and reservation.
 * Trusted services supply business context; the public factory permits Manual context only.
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
        public InventoryIssueContext $operationContext,
        public ?string $sourceType = null,
        public ?string $sourceCode = null,
        public ?string $reservationCode = null,
        array $serializedItemCodes = [],
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
        public ?string $expectedReservationSourceType = null,
        public ?string $expectedReservationSourceCode = null,
    ) {
        // Preserve caller provenance and explicit reservation references for service binding checks.
        parent::__construct($quantity);
        $this->inventoryItemCode = trim($inventoryItemCode);
        $this->locationCode = trim($locationCode);
        $this->sourceType = $sourceType === null ? null : trim($sourceType);
        $this->sourceCode = $sourceCode === null ? null : trim($sourceCode);
        $this->reservationCode = $reservationCode === null ? null : trim($reservationCode);
        $this->serializedItemCodes = self::normalizeSerializedItemCodes($serializedItemCodes);
        // Reservation source identifies the original sale line, not the issue allocation.
        $this->expectedReservationSourceType = $expectedReservationSourceType === null ? null : trim($expectedReservationSourceType);
        $this->expectedReservationSourceCode = $expectedReservationSourceCode === null ? null : trim($expectedReservationSourceCode);
    }

    public static function fromValidated(array $data, ?string $idempotencyKey = null): self
    {
        // Public issue always uses Manual context; source metadata cannot grant business privileges.
        return new self(
            inventoryItemCode: $data['inventory_item_code'],
            locationCode: $data['location_code'],
            quantity: $data['quantity'],
            operationContext: InventoryIssueContext::Manual,
            serializedItemCodes: $data['serialized_item_codes'] ?? [],
            reason: $data['reason'] ?? null,
            idempotencyKey: $idempotencyKey,
        );
    }

    public function toArray(): array
    {
        // Serialize the backed context value for deterministic audit/idempotency payloads.
        $data = parent::toArray();
        $data['operation_context'] = $this->operationContext->value;

        return $data;
    }
}
