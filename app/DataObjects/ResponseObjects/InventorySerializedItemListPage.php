<?php

namespace App\DataObjects\ResponseObjects;

/**
 * Paginated physical serialized items, distinct from measurement-unit options.
 *
 * @extends InventoryReadPage<InventorySerializedItemDetail>
 */
final class InventorySerializedItemListPage extends InventoryReadPage
{
    // Restrict page rows to the matching response contract.
    protected const ITEM_CLASS = InventorySerializedItemDetail::class;
}
