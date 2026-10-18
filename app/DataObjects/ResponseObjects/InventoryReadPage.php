<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use InvalidArgumentException;

/**
 * Serializes typed Inventory rows with the project's flat pagination metadata.
 * Row construction belongs to the service after batched lookups, preventing DTO queries.
 *
 * @template T of BaseDataObject
 */
abstract class InventoryReadPage extends BaseDataObject
{
    protected const ITEM_CLASS = BaseDataObject::class;

    /** @var list<T> */
    public array $items;

    /** @param list<T> $items */
    public function __construct(
        array $items,
        public int $currentPage,
        public int $lastPage,
        public int $perPage,
        public int $total,
    ) {
        // Validate metadata and enforce the bounded, typed page response.
        if ($currentPage < 1 || $lastPage < 1 || $perPage < 1 || $perPage > 100
            || $total < 0 || count($items) > $perPage) {
            throw new InvalidArgumentException('Invalid Inventory pagination metadata.');
        }
        $itemClass = static::ITEM_CLASS;
        foreach ($items as $item) {
            if (! $item instanceof $itemClass) {
                throw new InvalidArgumentException('Unexpected Inventory page row type.');
            }
        }
        $this->items = array_values($items);
    }

    /** @param list<T> $items Already mapped rows for this paginator's current page. */
    public static function fromPaginator(LengthAwarePaginator $paginator, array $items): static
    {
        // Copy metadata only; services provide prebuilt rows without lazy relationship loading.
        return new static(
            items: $items,
            currentPage: $paginator->currentPage(),
            lastPage: $paginator->lastPage(),
            perPage: $paginator->perPage(),
            total: $paginator->total(),
        );
    }
}
