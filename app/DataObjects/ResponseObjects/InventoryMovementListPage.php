<?php

namespace App\DataObjects\ResponseObjects;

/**
 * Paginated immutable history ordered by occurrence time and ID.
 *
 * @extends InventoryReadPage<InventoryMovementDetail>
 */
final class InventoryMovementListPage extends InventoryReadPage
{
    // Restrict page rows to the matching response contract.
    protected const ITEM_CLASS = InventoryMovementDetail::class;
}
