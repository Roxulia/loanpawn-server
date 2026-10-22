<?php

namespace App\Services\TenantModule\Ownership;

use App\Exceptions\{InvalidTenantRequest, TenantNotFound};
use App\Repository\PurchasingRepository;
use App\Services\BaseTenantService;

/** Purchase-owned source reads; Ownership never reaches into another module's repository. */
class PurchaseSourceService extends BaseTenantService
{
    public function __construct(private PurchasingRepository $repository) {}

    public function line(string $code, bool $lock = false)
    {
        // A foreign code has the same missing-resource contract as an unknown source.
        return $this->repository->ownershipLine($this->resolveCurrentTenantId(), $code, $lock)
            ?? throw new TenantNotFound(__('ownership.purchase_source_invalid'));
    }

    public function qualifiedLine(string $code): array
    {
        // Full settlement establishes acquisition; physical receipt is a separate fact.
        $line = $this->line($code, true);
        if ($line->order === null || (int) $line->order->tenant_id !== $this->resolveCurrentTenantId()
            || $line->order->status !== 'CONFIRMED') throw new InvalidTenantRequest(__('ownership.purchase_source_invalid'));
        $settlement = $this->repository->ownershipSettlement($this->resolveCurrentTenantId(), $line->purchase_order_id);
        if (! $settlement->settled) throw new InvalidTenantRequest(__('ownership.purchase_not_settled'));
        return [$line, $settlement->free ? $line->order->ordered_at->toDateString() : substr($settlement->settled_at, 0, 10)];
    }

    public function receipt(string $code, bool $lock = false)
    {
        // Reuse the existing resolver, including its eager purchase/receipt relationships.
        return $this->repository->receiptLineByCode($this->resolveCurrentTenantId(), $code, $lock)
            ?? throw new TenantNotFound(__('ownership.receipt_link_invalid'));
    }

    public function page(string $lineCode, $filter)
    {
        return $this->repository->ownershipReceiptPage($this->resolveCurrentTenantId(), $lineCode, $filter);
    }

    public function currencyIds(int $orderId): array
    {
        // Qualification keeps payment currency identity separate from a potentially shared display code.
        return $this->repository->ownershipPaymentCurrencyIds($this->resolveCurrentTenantId(), $orderId);
    }

    public function attachIdentity($line, int $itemId): void
    {
        // Persist the source's explicitly created identity through the Purchasing repository.
        $this->repository->updateOrderLine($line, ['inventory_item_id' => $itemId]);
    }

    public function quantities(array $lineCodes)
    {
        return $this->repository->ownershipReceivedQuantities($this->resolveCurrentTenantId(), $lineCodes);
    }

    public function itemReceipts(array $ownedIds)
    {
        return $this->repository->ownershipItemReceipts($this->resolveCurrentTenantId(), $ownedIds);
    }
}
