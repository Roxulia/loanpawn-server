<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class PawnCollateralItemCreate extends BaseDataObject
{
    public function __construct(
        public string $type,
        public string $name,
        public ?string $description = null,
        public ?string $brandName = null,
        public ?string $imageUrl = null,
        public float $estimatedValue = 0,
        public ?int $materialTypeId = null,
        public ?int $itemCategoryTypeId = null,
        public float $kyat = 0,
        public float $pal = 0,
        public float $yway = 0,
        public string $itemStatus = 'active',
        public bool $containsGemstones = false,
        public ?array $gemstoneDetails = null,
        public int $quantity = 1,
        public float $minimumRetailPrice = 0,
    ) {
    }
}
