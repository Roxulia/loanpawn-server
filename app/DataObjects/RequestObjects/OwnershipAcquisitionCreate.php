<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class OwnershipAcquisitionCreate extends BaseDataObject
{
    public function __construct(
        public string $inventoryItemCode,
        public float $quantity,
        public string $acquiredAt,
        public float $unitCostBasis,
        public ?float $estimatedUnitValue,
        public string $currencyCode,
        public ?string $description,
    ) {
    }

    public static function fromValidated(array $data): self
    {
        return new self(
            inventoryItemCode: $data['inventory_item_code'],
            quantity: (float) $data['quantity'],
            acquiredAt: $data['acquired_at'],
            unitCostBasis: (float) $data['unit_cost_basis'],
            estimatedUnitValue: isset($data['estimated_unit_value']) ? (float) $data['estimated_unit_value'] : null,
            currencyCode: strtoupper($data['currency_code']),
            description: $data['description'] ?? null,
        );
    }

    public function toArray(): array
    {
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
