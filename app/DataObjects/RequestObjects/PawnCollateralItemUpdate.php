<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class PawnCollateralItemUpdate extends BaseDataObject
{
    public function __construct(
        public int $itemId,
        public string $code = '',
        public ?string $type = null,
        public ?string $name = null,
        public ?string $description = null,
        public ?string $brandName = null,
        public ?string $imageUrl = null,
        public ?float $estimatedValue = null,
        public ?int $materialTypeId = null,
        public ?int $itemCategoryTypeId = null,
        public ?float $kyat = null,
        public ?float $pal = null,
        public ?float $yway = null,
        public ?string $itemStatus = null,
        public ?bool $containsGemstones = null,
        public ?array $gemstoneDetails = null,
        public ?int $quantity = null,
        public ?float $minimumRetailPrice = null,
        public int $updateKey = 0,
    ) {
    }
}
