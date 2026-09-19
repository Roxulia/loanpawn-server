<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\PawnModule\PawnCollateralPackItem;

class CollateralPackItemDetail extends BaseDataObject
{
    public int $id;
    public string $name;
    public int $quantity;
    public ?string $kyat;
    public ?string $pal;
    public ?string $yway;
    public ?int $materialTypeId;
    public ?string $materialTypeName;
    public bool $hasImageReference;
    public ?string $imageUrl = null;
    public ?string $imageUrlExpiresAt = null;

    public static function fromModel(PawnCollateralPackItem $item): self
    {
        $detail = new self();
        $detail->id = $item->id;
        $detail->name = $item->name;
        $detail->quantity = $item->quantity;
        $detail->kyat = $item->kyat;
        $detail->pal = $item->pal;
        $detail->yway = $item->yway;
        $detail->materialTypeId = $item->material_type_id;
        $detail->materialTypeName = $item->materialType?->name;
        $detail->hasImageReference = filled($item->image_url);
        return $detail;
    }
}
