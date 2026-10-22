<?php

namespace App\DTOs\Ownership\Public\RequestObjects;

use App\DTOs\Ownership\Internal\RequestObjects\OwnershipSource;

use App\DataObjects\BaseDataObject;
use App\Support\OwnershipCost;
use App\Support\OwnershipQuantity;

/** Carries exact acquisition values; public input cannot supply trusted source context. */
class OwnershipAcquisitionCreate extends BaseDataObject
{
    public string $quantity;
    /** Original cost per acquired unit in currencyCode, e.g. "125000.1234" MMK; stored as DECIMAL(18,4) text. */
    public string $unitCostBasis;
    /** Estimated value per unit in the same currency, e.g. "150000.0000"; null means no estimate, "0.0000" means zero. */
    public ?string $estimatedUnitValue;

    public function __construct(
        public string $inventoryItemCode,
        string|int|float $quantity,
        public string $acquiredAt,
        mixed $unitCostBasis,
        mixed $estimatedUnitValue,
        public string $currencyCode,
        public ?string $description,
        public ?OwnershipSource $source = null,
    ) {
        // Preserve the existing Pawn value-only placeholder; recordAcquisition validates its resolved quantity.
        $this->quantity = in_array($quantity, [0, 0.0, '0', '0.000'], true)
            ? '0.000' : OwnershipQuantity::normalize($quantity);
        $this->inventoryItemCode = trim($inventoryItemCode);
        $this->acquiredAt = trim($acquiredAt);
        $this->currencyCode = strtoupper(trim($currencyCode));
        // Normalize scalar decimal values while keeping ordinary JSON string/null serialization.
        $this->unitCostBasis = OwnershipCost::normalize($unitCostBasis);
        $this->estimatedUnitValue = OwnershipCost::normalizeOptional($estimatedUnitValue);
    }

    public static function fromValidated(array $data): self
    {
        // Allowlist public values; never hydrate tenant, settlement, source, or bypass authority.
        return new self(
            inventoryItemCode: $data['inventory_item_code'],
            quantity: OwnershipQuantity::normalize($data['quantity']),
            acquiredAt: $data['acquired_at'],
            unitCostBasis: $data['unit_cost_basis'],
            estimatedUnitValue: $data['estimated_unit_value'] ?? null,
            currencyCode: $data['currency_code'],
            description: $data['description'] ?? null,
        );
    }

    public static function fromSource(OwnershipSource $source): self
    {
        // Copy only facts supplied by the internal resolver contract; eligibility is checked by services.
        return new self(
            inventoryItemCode: $source->inventoryItemCode,
            quantity: $source->quantity,
            acquiredAt: $source->acquiredAt,
            unitCostBasis: $source->unitCostBasis,
            estimatedUnitValue: $source->estimatedUnitValue,
            currencyCode: $source->currencyCode,
            description: $source->description,
            source: $source,
        );
    }

    public function toArray(): array
    {
        // Keep exact decimal strings at the serialization boundary and omit internal source authority.
        return [
            'inventory_item_code' => $this->inventoryItemCode,
            'quantity' => $this->quantity,
            'acquired_at' => $this->acquiredAt,
            'unit_cost_basis' => $this->unitCostBasis,
            'estimated_unit_value' => $this->estimatedUnitValue,
            'currency_code' => $this->currencyCode,
            'description' => $this->description,
        ];
    }
}
