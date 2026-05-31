<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\PawnModule\PawnCollateralItem;

class LoanContractSlipItemDetail extends BaseDataObject
{
    public int $id;
    public string $code;
    public ?int $loanContractId;
    public string $type;
    public string $name;
    public ?string $description;
    public ?string $brandName;
    public ?string $imageUrl;
    public string $estimatedValue;
    public ?int $materialTypeId;
    public ?string $materialTypeName;
    public string $kyat;
    public string $pal;
    public string $yway;
    public string $itemStatus;
    public bool $containsGemstones;
    public ?array $gemstoneDetails;
    public int $quantity;
    public string $minimumRetailPrice;
    public bool $isDeleted;
    public int $updateKey;

    public static function fromModel(PawnCollateralItem $item): self
    {
        $detail = new self();
        $detail->id = $item->id;
        $detail->code = $item->code;
        $detail->loanContractId = $item->loan_contract_id;
        $detail->type = $item->type;
        $detail->name = $item->name;
        $detail->description = $item->description;
        $detail->brandName = $item->brand_name;
        $detail->imageUrl = $item->image_url;
        $detail->estimatedValue = (string) $item->estimated_value;
        $detail->materialTypeId = $item->material_type_id;
        $detail->materialTypeName = $item->materialType?->name;
        $detail->kyat = (string) $item->kyat;
        $detail->pal = (string) $item->pal;
        $detail->yway = (string) $item->yway;
        $detail->itemStatus = $item->item_status;
        $detail->containsGemstones = (bool) $item->contains_gemstones;
        $detail->gemstoneDetails = $item->gemstone_details;
        $detail->quantity = (int) $item->quantity;
        $detail->minimumRetailPrice = (string) $item->minimum_retail_price;
        $detail->isDeleted = (bool) $item->is_deleted;
        $detail->updateKey = (int) $item->update_key;
        return $detail;
    }
}
