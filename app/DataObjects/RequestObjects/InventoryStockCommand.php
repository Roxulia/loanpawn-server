<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;
use App\Exceptions\InvalidTenantRequest;
use App\Support\InventoryQuantity;

/**
 * Shares exact quantity normalization between Inventory stock commands.
 * Contains no tenant selector, database IDs, or privileged operation bypass flag.
 */
abstract class InventoryStockCommand extends BaseDataObject
{
    public string $quantity;

    protected function __construct(string|int|float $quantity)
    {
        // Preserve exact decimal text; resolved tracking-mode checks belong to the service.
        $this->quantity = InventoryQuantity::format(InventoryQuantity::parse($quantity));
    }

    protected static function normalizeSerializedItemCodes(array $codes): array
    {
        // Require string references and retain duplicates for explicit service validation.
        return array_map(static function (mixed $code): string {
            if (! is_string($code) || trim($code) === '') {
                throw new InvalidTenantRequest();
            }

            return trim($code);
        }, array_values($codes));
    }
}
