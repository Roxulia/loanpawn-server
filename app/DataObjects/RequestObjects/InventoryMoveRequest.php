<?php

namespace App\DataObjects\RequestObjects;

use App\Exceptions\InvalidTenantRequest;

/**
 * Carries a public stock move with its mandatory expected item version.
 * Selected serialized units use public codes, never database identifiers.
 */
final class InventoryMoveRequest extends InventoryStockCommand
{
    /** @var list<string> */
    public array $inventoryUnitCodes;

    public function __construct(
        public string $inventoryItemCode,
        public string $fromLocationCode,
        public string $toLocationCode,
        string|int|float $quantity,
        public int $updateKey,
        array $inventoryUnitCodes = [],
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
    ) {
        // Normalize exact quantities and references before later locking/state validation.
        parent::__construct($quantity);
        $this->inventoryItemCode = trim($inventoryItemCode);
        $this->fromLocationCode = trim($fromLocationCode);
        $this->toLocationCode = trim($toLocationCode);
        $this->inventoryUnitCodes = self::normalizeUnitCodes($inventoryUnitCodes);
        if ($updateKey < 0 || $updateKey > 4_294_967_295) {
            throw new InvalidTenantRequest();
        }
    }

    public static function fromValidated(array $data, ?string $idempotencyKey = null): self
    {
        // Require an explicit expected version; omit public source/context overrides.
        if (! isset($data['update_key'])) {
            throw new InvalidTenantRequest();
        }

        return new self(
            inventoryItemCode: $data['inventory_item_code'],
            fromLocationCode: $data['from_location_code'],
            toLocationCode: $data['to_location_code'],
            quantity: $data['quantity'],
            updateKey: (int) $data['update_key'],
            inventoryUnitCodes: $data['inventory_unit_codes'] ?? [],
            reason: $data['reason'] ?? null,
            idempotencyKey: $idempotencyKey,
        );
    }
}
