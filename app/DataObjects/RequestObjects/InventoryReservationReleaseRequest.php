<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;
use App\Exceptions\InvalidTenantRequest;
use App\Support\InventoryQuantity;

/**
 * Describes a trusted caller's full or partial stock-reservation release.
 * Null quantity with no selected codes means release all remaining stock.
 * Partial serialized release requires explicit codes; provenance binds the original source.
 */
final class InventoryReservationReleaseRequest extends BaseDataObject
{
    public ?string $quantity;
    /** @var list<string> */
    public array $serializedItemCodes;

    public function __construct(
        public string $inventoryItemCode,
        public string $locationCode,
        public string $reservationCode,
        public string $expectedSourceType,
        public string $expectedSourceCode,
        string|int|float|null $quantity = null,
        array $serializedItemCodes = [],
        public ?string $idempotencyKey = null,
    ) {
        // Normalize required business references without accepting an unbound release.
        foreach ([
            'inventoryItemCode',
            'locationCode',
            'reservationCode',
            'expectedSourceType',
            'expectedSourceCode'
            ] as $field) {
            $this->{$field} = trim($this->{$field});
            if ($this->{$field} === '') {
                throw new InvalidTenantRequest(__('inventory.reservation_unavailable'));
            }
        }

        // Retain exact quantity and duplicate codes for explicit membership validation.
        $this->quantity = $quantity === null ? null : InventoryQuantity::format(InventoryQuantity::parse($quantity));
        $this->serializedItemCodes = array_map(static function (mixed $code): string {
            if (! is_string($code) || trim($code) === '' || mb_strlen(trim($code)) > 40) {
                throw new InvalidTenantRequest(__('inventory.unit_selection_invalid'));
            }
            return trim($code);
        }, array_values($serializedItemCodes));
    }

    public function releasesAll(): bool
    {
        // An explicit quantity or selection always describes a partial/selected release.
        return $this->quantity === null && $this->serializedItemCodes === [];
    }
}
