<?php

namespace App\Enums;

/**
 * Identifies which workflow owns the primary success audit.
 * This internal descriptor provides no permissions or feature-gate bypass.
 */
enum InventoryOperationOwner: string
{
    case PublicInventory = 'PUBLIC_INVENTORY';
    case BusinessCaller = 'BUSINESS_CALLER';
}
