<?php

namespace App\DataObjects\ResponseObjects;

/**
 * Paginated item summaries without serialized-unit collections.
 *
 * @extends InventoryReadPage<InventoryItemSummary>
 */
final class InventoryItemListPage extends InventoryReadPage
{
    // Restrict page rows to the matching response contract.
    protected const ITEM_CLASS = InventoryItemSummary::class;
}
