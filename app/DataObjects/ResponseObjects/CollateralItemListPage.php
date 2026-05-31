<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\PawnModule\PawnCollateralItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CollateralItemListPage extends BaseDataObject
{
    /**
     * @var CollateralItemListItem[]
     */
    public array $items;
    public int $currentPage;
    public int $lastPage;
    public int $perPage;
    public int $total;

    public static function fromPaginator(LengthAwarePaginator $paginator): self
    {
        $detail = new self();
        $detail->items = array_map(
            fn (PawnCollateralItem $item) => CollateralItemListItem::fromRow($item),
            $paginator->items()
        );
        $detail->currentPage = $paginator->currentPage();
        $detail->lastPage = $paginator->lastPage();
        $detail->perPage = $paginator->perPage();
        $detail->total = $paginator->total();

        return $detail;
    }
}
