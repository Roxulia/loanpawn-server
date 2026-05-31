<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class TenantListPage extends BaseDataObject
{
    /**
     * @var TenantListItem[]
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
            fn ($tenant) => TenantListItem::fromModel($tenant),
            $paginator->items()
        );
        $detail->currentPage = $paginator->currentPage();
        $detail->lastPage = $paginator->lastPage();
        $detail->perPage = $paginator->perPage();
        $detail->total = $paginator->total();

        return $detail;
    }
}
