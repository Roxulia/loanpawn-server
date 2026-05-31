<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class LoanContractSlipListPage extends BaseDataObject
{
    /**
     * @var LoanContractSlipDetail[]
     */
    public array $items;
    public int $currentPage;
    public int $lastPage;
    public int $perPage;
    public int $total;

    public static function fromPaginator(LengthAwarePaginator $paginator): self
    {
        $page = new self();
        $page->items = array_map(
            fn ($slip) => LoanContractSlipDetail::fromModel($slip),
            $paginator->items()
        );
        $page->currentPage = $paginator->currentPage();
        $page->lastPage = $paginator->lastPage();
        $page->perPage = $paginator->perPage();
        $page->total = $paginator->total();

        return $page;
    }
}
