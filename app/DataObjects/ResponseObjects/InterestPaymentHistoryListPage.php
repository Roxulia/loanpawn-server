<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\PawnModule\PawnInterestPayment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class InterestPaymentHistoryListPage extends BaseDataObject
{
    /**
     * @var InterestPaymentHistoryItem[]
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
            fn (PawnInterestPayment $payment): InterestPaymentHistoryItem => InterestPaymentHistoryItem::fromModel($payment),
            $paginator->items()
        );
        $page->currentPage = $paginator->currentPage();
        $page->lastPage = $paginator->lastPage();
        $page->perPage = $paginator->perPage();
        $page->total = $paginator->total();

        return $page;
    }
}
