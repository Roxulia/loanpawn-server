<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PawnRedemptionListPage extends BaseDataObject
{
    /**
     * @var PawnRedemptionDetail[]
     */
    public array $items;
    public int $page;
    public int $perPage;
    public int $total;

    public static function fromPaginator(LengthAwarePaginator $paginator): self
    {
        $page = new self();
        $page->items = array_map(
            fn ($redemption) => PawnRedemptionDetail::fromModel($redemption),
            $paginator->items()
        );
        $page->page = $paginator->currentPage();
        $page->perPage = $paginator->perPage();
        $page->total = $paginator->total();

        return $page;
    }
}
