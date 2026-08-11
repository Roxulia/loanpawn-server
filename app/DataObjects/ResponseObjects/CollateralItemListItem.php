<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;

class CollateralItemListItem extends BaseDataObject
{
    public int $id;
    public string $code;
    public int $updateKey;
    public string $itemType;
    public string $name;
    public ?string $description;
    public ?string $imageUrl;
    public bool $hasImageReference;
    public string $itemStatus;
    public ?string $createdAt;

    public static function fromRow(object $row): self
    {
        $detail = new self();
        $detail->id = (int) $row->id;
        $detail->code = (string) $row->code;
        $detail->updateKey = (int) $row->update_key;
        $detail->itemType = (string) $row->type;
        $detail->name = (string) $row->name;
        $detail->description = $row->description;
        $detail->imageUrl = null;
        $detail->hasImageReference = filled($row->image_url);
        $detail->itemStatus = (string) $row->item_status;
        $detail->createdAt = $row->created_at?->toISOString();

        return $detail;
    }
}
