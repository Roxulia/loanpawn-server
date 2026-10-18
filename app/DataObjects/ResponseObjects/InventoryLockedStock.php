<?php

namespace App\DataObjects\ResponseObjects;

use Illuminate\Support\Collection;

/**
 * Holds current stock records protected by the caller's transaction.
 * This internal snapshot must not be cached or exposed as an HTTP response.
 * Records are keyed by public code, custody by serialized ID, and balances by item/location ID.
 * Balance values are integer thousandths; callers must rebuild after any stock mutation.
 * Location pairs without checkpoint or movement rows are omitted and must be read as zero.
 */
final class InventoryLockedStock
{
    public function __construct(
        public readonly Collection $items,
        public readonly Collection $locations,
        public readonly Collection $reservations,
        public readonly Collection $serializedItems,
        public readonly Collection $custody,
        public readonly array $balances,
    ) {
    }
}
