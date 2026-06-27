<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class TenantCustomerListPage extends BaseDataObject
{
    /**
     * @var TenantCustomerListItem[]
     */
    public array $items;
    public TenantCustomerListSummary $summary;
    public int $currentPage;
    public int $lastPage;
    public int $perPage;
    public int $total;

    /**
     * @param Collection<int, TenantCustomerLastActivity> $activitiesByCustomerId
     */
    public static function fromPaginator(
        LengthAwarePaginator $paginator,
        TenantCustomerListSummary $summary,
        Collection $activitiesByCustomerId,
    ): self
    {
        $detail = new self();
        $detail->items = array_map(
            fn ($customer) => TenantCustomerListItem::fromModelWithActivity(
                $customer,
                $activitiesByCustomerId->get($customer->id, new TenantCustomerLastActivity(
                    date: null,
                    status: 'NO ACTIVITY',
                    label: 'No pawn activity recorded',
                    tone: 'neutral',
                )),
            ),
            $paginator->items()
        );
        $detail->summary = $summary;
        $detail->currentPage = $paginator->currentPage();
        $detail->lastPage = $paginator->lastPage();
        $detail->perPage = $paginator->perPage();
        $detail->total = $paginator->total();

        return $detail;
    }
}
