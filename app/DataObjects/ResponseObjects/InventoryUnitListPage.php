<?php

namespace App\DataObjects\ResponseObjects;

/**
 * Paginated physical serialized units, distinct from measurement-unit options.
 *
 * @extends InventoryReadPage<InventoryUnitDetail>
 */
final class InventoryUnitListPage extends InventoryReadPage
{
    // Restrict page rows to the matching response contract.
    protected const ITEM_CLASS = InventoryUnitDetail::class;
}
