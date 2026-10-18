<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

/**
 * Carries validated location creation fields using public business values.
 * Tenant identity and generated location codes remain owned by the service.
 */
final class InventoryLocationCreate extends BaseDataObject
{
    public function __construct(
        public string $name,
        public string $type,
        public bool $isSellable = true,
        public ?string $idempotencyKey = null,
    ) {
        // Normalize editable text without accepting tenant or generated code overrides.
        $this->name = trim($name);
        $this->type = strtoupper(trim($type));
    }

    public static function fromValidated(array $data, ?string $idempotencyKey = null): self
    {
        // Read only controller-validated fields and the separately resolved request key.
        return new self(
            name: $data['name'],
            type: $data['type'],
            isSellable: $data['is_sellable'] ?? true,
            idempotencyKey: $idempotencyKey,
        );
    }
}
