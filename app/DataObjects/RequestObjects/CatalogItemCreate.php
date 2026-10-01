<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class CatalogItemCreate extends BaseDataObject
{
    public function __construct(
        public string $name,
        public ?string $description,
        public ?int $categoryId,
        public ?string $sku,
        public ?string $barcode,
        public string $trackingMode,
        public ?int $unitId,
    ) {
    }

    public static function fromValidated(array $data): self
    {
        return new self(
            name: $data['name'],
            description: $data['description'] ?? null,
            categoryId: isset($data['category_id']) ? (int) $data['category_id'] : null,
            sku: $data['sku'] ?? null,
            barcode: $data['barcode'] ?? null,
            trackingMode: $data['tracking_mode'] ?? 'QUANTITY',
            unitId: isset($data['unit_id']) ? (int) $data['unit_id'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'category_id' => $this->categoryId,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'tracking_mode' => $this->trackingMode,
            'unit_id' => $this->unitId,
        ];
    }
}
