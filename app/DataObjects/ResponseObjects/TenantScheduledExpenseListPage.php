<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class TenantScheduledExpenseListPage extends BaseDataObject
{
    public array $data;

    public function __construct(array $data = []) { $this->data = $data; }

    public function toArray(): array { return $this->data; }

    public static function fromPaginator(LengthAwarePaginator $paginator): self
    {
        return new self([
            'items' => array_map(fn ($item) => TenantScheduledExpenseDetail::fromModel($item)->toArray(), $paginator->items()),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ]);
    }
}
