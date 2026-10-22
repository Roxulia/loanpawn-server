<?php

namespace App\DTOs\Ownership\Public\ResponseObjects;

use App\DataObjects\ResponseObjects\InventoryReadPage;

/**
 * Bounded OwnershipPiece rows with flat page metadata and no model expansion.
 * Reuses Inventory's typed-row validation, maximum page size, and paginator metadata copying.
 *
 * @extends InventoryReadPage<OwnershipPiece>
 */
final class OwnershipPiecePage extends InventoryReadPage
{
    // Reject raw arrays, models, and response DTOs intended for another collection.
    protected const ITEM_CLASS = OwnershipPiece::class;
}
