<?php

namespace App\DataObjects\RequestObjects;

use App\Exceptions\InvalidTenantRequest;

/**
 * Carries an explicit stock adjustment, including its direction and selected identities.
 * Public factories cannot supply trusted source metadata or bypass operation restrictions.
 */
final class InventoryAdjustmentRequest extends InventoryStockCommand
{
    /** @var list<string> */
    public array $serializedItemCodes;
    /** @var list<string|null> */
    public array $serializedItemIdentifiers;

    public function __construct(
        public string $inventoryItemCode,
        public string $locationCode,
        string|int|float $quantity,
        public string $direction,
        public string $reason,
        array $serializedItemCodes = [],
        array $serializedItemIdentifiers = [],
        public ?string $sourceType = null,
        public ?string $sourceCode = null,
        public ?string $idempotencyKey = null,
    ) {
        // Normalize quantity and require a valid adjustment direction and reason.
        parent::__construct($quantity);
        $this->inventoryItemCode = trim($inventoryItemCode);
        $this->locationCode = trim($locationCode);
        $this->direction = strtoupper(trim($direction));
        $this->reason = trim($reason);
        if (! in_array($this->direction, [
            'IN',
            'OUT'
            ], true) || $this->reason === '') {
            throw new InvalidTenantRequest();
        }
        $this->serializedItemCodes = self::normalizeSerializedItemCodes($serializedItemCodes);
        $this->serializedItemIdentifiers = self::normalizeSerializedItemIdentifiers($serializedItemIdentifiers);
    }

    public static function fromValidated(array $data, ?string $idempotencyKey = null): self
    {
        // Hydrate only validated public fields; source metadata belongs to trusted callers.
        return new self(
            inventoryItemCode: $data['inventory_item_code'],
            locationCode: $data['location_code'],
            quantity: $data['quantity'],
            direction: $data['direction'],
            reason: $data['reason'],
            serializedItemCodes: $data['serialized_item_codes'] ?? [],
            serializedItemIdentifiers: $data['serialized_item_identifiers'] ?? [],
            idempotencyKey: $idempotencyKey,
        );
    }
}
