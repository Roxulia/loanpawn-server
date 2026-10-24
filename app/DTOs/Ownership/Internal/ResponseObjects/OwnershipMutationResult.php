<?php

namespace App\DTOs\Ownership\Internal\ResponseObjects;

use App\DTOs\Ownership\Public\ResponseObjects\OwnershipResult;
use App\Models\OwnershipModule\OwnedItem;

/** Carries the existing audit target alongside the public response without exposing models on the wire. */
final readonly class OwnershipMutationResult
{
    public function __construct(public OwnershipResult $response, public OwnedItem $owner) {}
}
