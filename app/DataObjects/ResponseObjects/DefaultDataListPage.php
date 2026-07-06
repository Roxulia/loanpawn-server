<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class DefaultDataListPage extends BaseDataObject
{
    public array $items;
    public int $currentPage;
    public int $lastPage;
    public int $perPage;
    public int $total;

    public static function fromPaginator(LengthAwarePaginator $paginator): self
    {
        $page = new self();
        $page->items = array_map(
            fn ($item) => method_exists($item, 'toArray') ? $item->toArray() : (array) $item,
            $paginator->items()
        );
        $page->currentPage = $paginator->currentPage();
        $page->lastPage = $paginator->lastPage();
        $page->perPage = $paginator->perPage();
        $page->total = $paginator->total();

        return $page;
    }
}
