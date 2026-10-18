<?php

namespace App\DataObjects\RequestObjects;

/**
 * Describes receipt into a public location, optionally creating a freeform item.
 * Source references are set by trusted callers; the HTTP factory omits them.
 */
final class InventoryReceiveRequest extends InventoryStockCommand
{
    /** @var list<string|null> */
    public array $serializedItemIdentifiers;

    public function __construct(
        public string $locationCode,
        string|int|float $quantity,
        public ?string $inventoryItemCode = null,
        public ?string $catalogItemCode = null,
        public ?string $name = null,
        public ?string $description = null,
        public ?string $trackingMode = null,
        public ?string $unitCode = null,
        array $serializedItemIdentifiers = [],
        public ?string $reason = null,
        public ?string $sourceType = null,
        public ?string $sourceCode = null,
        public ?string $idempotencyKey = null,
    ) {
        // Normalize quantity and public references without resolving tenant-owned records.
        parent::__construct($quantity);
        $this->locationCode = trim($locationCode);
        $this->inventoryItemCode = $inventoryItemCode === null ? null : trim($inventoryItemCode);
        $this->catalogItemCode = $catalogItemCode === null ? null : trim($catalogItemCode);
        $this->name = $name === null ? null : trim($name);
        $this->trackingMode = $trackingMode === null ? null : strtoupper(trim($trackingMode));
        $this->unitCode = $unitCode === null ? null : trim($unitCode);

        // Preserve null identifiers and the literal "0" while removing surrounding spaces.
        $this->serializedItemIdentifiers = array_map(static function (?string $identifier): ?string {
            $identifier = $identifier === null ? null : trim($identifier);

            return $identifier === '' ? null : $identifier;
        }, array_values($serializedItemIdentifiers));
    }

    public static function fromValidated(array $data, ?string $idempotencyKey = null): self
    {
        // Public input cannot select trusted source metadata or an internal bypass context.
        return new self(
            locationCode: $data['location_code'],
            quantity: $data['quantity'],
            inventoryItemCode: $data['inventory_item_code'] ?? null,
            catalogItemCode: $data['catalog_item_code'] ?? null,
            name: $data['name'] ?? null,
            description: $data['description'] ?? null,
            trackingMode: $data['tracking_mode'] ?? null,
            unitCode: $data['unit_code'] ?? null,
            serializedItemIdentifiers: $data['serialized_item_identifiers'] ?? [],
            reason: $data['reason'] ?? null,
            idempotencyKey: $idempotencyKey,
        );
    }
}
