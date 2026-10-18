<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;
use App\Exceptions\InvalidTenantRequest;

/**
 * Carries a partial location edit and the client's expected update_key.
 * Null editable fields mean unchanged; false boolean values remain explicit edits.
 */
final class InventoryLocationUpdate extends BaseDataObject
{
    public function __construct(
        public string $locationCode,
        public int $updateKey,
        public ?string $name = null,
        public ?string $type = null,
        public ?bool $isActive = null,
        public ?bool $isSellable = null,
        public ?string $idempotencyKey = null,
    ) {
        // Normalize references and reject an impossible optimistic version.
        $this->locationCode = trim($locationCode);
        $this->name = $name === null ? null : trim($name);
        $this->type = $type === null ? null : strtoupper(trim($type));
        if ($updateKey < 0 || $updateKey > 4_294_967_295) {
            throw new InvalidTenantRequest();
        }
    }

    public static function fromValidated(string $locationCode, array $data, ?string $idempotencyKey = null): self
    {
        // Keep the route reference separate from editable fields and require a version.
        if (! isset($data['update_key'])) {
            throw new InvalidTenantRequest();
        }

        return new self(
            locationCode: $locationCode,
            updateKey: (int) $data['update_key'],
            name: $data['name'] ?? null,
            type: $data['type'] ?? null,
            isActive: isset($data['is_active']) ? (bool) $data['is_active'] : null,
            isSellable: isset($data['is_sellable']) ? (bool) $data['is_sellable'] : null,
            idempotencyKey: $idempotencyKey,
        );
    }
}
