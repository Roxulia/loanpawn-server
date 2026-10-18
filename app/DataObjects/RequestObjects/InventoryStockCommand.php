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
    public ?string $idempotencyKey = null;

    protected function __construct(string|int|float $quantity)
    {
        // Preserve exact decimal text; resolved tracking-mode checks belong to the service.
        $this->quantity = InventoryQuantity::format(InventoryQuantity::parse($quantity));
    }

    protected static function normalizeSerializedItemIdentifiers(array $identifiers): array
    {
        // Preserve null and "0" while refusing silent coercion of invalid identifier types.
        return array_map(static function (mixed $identifier): ?string {
            if ($identifier !== null && ! is_string($identifier)) {
                throw new InvalidTenantRequest(__('inventory.identifier_invalid'));
            }
            $identifier = $identifier === null ? null : trim($identifier);

            return $identifier === '' ? null : $identifier;
        }, array_values($identifiers));
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
