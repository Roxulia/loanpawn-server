<?php

namespace App\Services\TenantModule\Ownership;

use App\DTOs\Ownership\Internal\ResponseObjects\OwnershipAcquisitionValues;
use App\Exceptions\InvalidTenantRequest;
use App\Models\InventoryModule\InventoryItem;
use App\Models\PurchasingModule\PurchaseOrderLine;
use App\Services\BaseTenantService;
use App\Services\TenantModule\TenantCurrencyService;
use App\Support\{InventoryQuantity, OwnershipCost};

/** Purchasing owns settlement qualification; this helper validates and returns exact acquisition snapshots. */
class AcquisitionValidationService extends BaseTenantService
{
    public function __construct(private PurchaseSourceService $purchases, private TenantCurrencyService $currencies) {}

    public function validatePurchaseAcquisition(PurchaseOrderLine $line, InventoryItem $item): OwnershipAcquisitionValues
    {
        // Reuse normalized values during lot creation instead of validating the unit cost a second time.
        $tenant = $this->resolveCurrentTenantId();
        $this->validatePurchaseInventoryIdentity($line, $item, $tenant);
        $values = new OwnershipAcquisitionValues(
            InventoryQuantity::format(InventoryQuantity::parse($line->ordered_quantity, $item->tracking_mode)),
            OwnershipCost::normalize($line->unit_price),
        );
        $this->validatePurchasePaymentCurrencies($line, $tenant);
        return $values;
    }

    private function validatePurchaseInventoryIdentity(PurchaseOrderLine $line, InventoryItem $item, int $tenant): void
    {
        // Preserve exact tenant, assigned identity, tracking mode and measurement-unit association.
        if ((int) $line->tenant_id !== $tenant || (int) $item->tenant_id !== $tenant
            || (int) $line->inventory_item_id !== (int) $item->id
            || $line->tracking_mode !== $item->tracking_mode || $line->unit_code !== $item->unit->code) {
            throw new InvalidTenantRequest(__('ownership.purchase_source_invalid'));
        }
    }

    private function validatePurchasePaymentCurrencies(PurchaseOrderLine $line, int $tenant): void
    {
        // Code visibility and actual paid-account identities are distinct rules; retain both.
        $code = $line->order->currency_code;
        $this->currencies->findActiveVisibleByCodeForTenant($tenant, $code);
        $ids = $this->purchases->currencyIds((int) $line->purchase_order_id);
        foreach ($this->currencies->findActiveVisibleCurrenciesByIdsForTenant($tenant, $ids) as $currency) {
            if ($currency->code !== $code) throw new InvalidTenantRequest(__('ownership.purchase_source_invalid'));
        }
    }
}
