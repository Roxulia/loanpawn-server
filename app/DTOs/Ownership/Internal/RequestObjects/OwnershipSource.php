<?php

declare(strict_types=1);

namespace App\DTOs\Ownership\Internal\RequestObjects;

use App\DataObjects\BaseDataObject;
use App\Exceptions\InvalidTenantRequest;
use App\Support\OwnershipCost;
use App\Support\OwnershipQuantity;

/**
 * Carries acquisition facts resolved by a trusted business service.
 * Identifies the tenant, business source, Inventory item, and original acquisition values.
 * This object normalizes values; it does not prove settlement, tenant access, or source eligibility.
 * Public request factories must not construct it from caller-supplied authority flags.
 */
final class OwnershipSource extends BaseDataObject
{
    public readonly int $tenantId;
    public readonly string $sourceModule;
    public readonly string $sourceType;
    public readonly string $sourceCode;
    public readonly string $inventoryItemCode;
    public readonly string $trackingMode;
    public readonly string $quantity;
    public readonly string $acquiredAt;
    /** Original acquisition cost per unit in currencyCode, e.g. "125000.1234", with exactly four decimals. */
    public readonly string $unitCostBasis;
    /** Estimated value per unit in the same currency, e.g. "150000.0000"; null means unknown. */
    public readonly ?string $estimatedUnitValue;
    public readonly string $currencyCode;
    public readonly ?string $description;

    public function __construct(
        int $tenantId,
        string $sourceModule,
        string $sourceType,
        string $sourceCode,
        string $inventoryItemCode,
        string $trackingMode,
        mixed $quantity,
        string $acquiredAt,
        mixed $unitCostBasis,
        mixed $estimatedUnitValue,
        string $currencyCode,
        ?string $description,
    ) {
        // Normalize stable references without looking up or accepting authority for stored records.
        if ($tenantId <= 0) {
            throw new InvalidTenantRequest(__('ownership.unsupported_source'));
        }
        $this->tenantId = $tenantId;
        $this->sourceModule = strtoupper(self::requiredCode($sourceModule));
        $this->sourceType = self::requiredCode($sourceType);
        $this->sourceCode = self::requiredCode($sourceCode);
        $this->inventoryItemCode = self::requiredCode($inventoryItemCode);
        $this->trackingMode = strtoupper(trim($trackingMode));

        // Preserve exact acquisition snapshots using the reviewed Ownership helper contracts.
        $this->quantity = OwnershipQuantity::normalize($quantity, $this->trackingMode);
        $this->unitCostBasis = OwnershipCost::normalize($unitCostBasis);
        $this->estimatedUnitValue = OwnershipCost::normalizeOptional($estimatedUnitValue);
        $this->acquiredAt = trim($acquiredAt);
        $this->currencyCode = strtoupper(trim($currencyCode));
        $this->description = $description === null ? null : trim($description);
    }

    private static function requiredCode(string $code): string
    {
        // Preserve case and the literal "0" while rejecting missing references.
        $code = trim($code);
        if ($code === '') {
            throw new InvalidTenantRequest(__('ownership.unsupported_source'));
        }

        return $code;
    }
}
