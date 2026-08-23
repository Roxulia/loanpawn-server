<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class TenantUserNotificationListPage extends BaseDataObject
{
    public function __construct(
        public array $items,
        public int $unreadCount,
        public int $currentPage,
        public int $lastPage,
        public int $perPage,
        public int $total,
    ) {}

    public static function fromPaginator(LengthAwarePaginator $page, int $unreadCount): self
    {
        return new self(
            items: array_map(
                fn ($notification) => TenantUserNotificationResource::fromModel($notification)->toArray(),
                $page->items(),
            ),
            unreadCount: $unreadCount,
            currentPage: $page->currentPage(),
            lastPage: $page->lastPage(),
            perPage: $page->perPage(),
            total: $page->total(),
        );
    }
}
