<?php

namespace App\DTOs\Ownership\Internal\ResponseObjects;

use Illuminate\Support\Collection;

/** Internal return selection validated under the caller's transaction; not a public response. */
final readonly class OwnershipReturnSelection
{
    public function __construct(
        public int $tenantId, public int $ownedItemId, public int $lotId,
        public string $sourceType, public int $amount, public array $pieceCodes,
        public Collection $members, public array $pieces = [],
    ) {}
}
