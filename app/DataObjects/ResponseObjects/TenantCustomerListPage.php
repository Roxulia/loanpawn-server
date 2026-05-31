<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class TenantCustomerListPage extends BaseDataObject
{
    /**
     * @var TenantCustomerDetail[]
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
            fn ($customer) => TenantCustomerDetail::fromModel($customer),
            $paginator->items()
        );
        $detail->currentPage = $paginator->currentPage();
        $detail->lastPage = $paginator->lastPage();
        $detail->perPage = $paginator->perPage();
        $detail->total = $paginator->total();

        return $detail;
    }
}
