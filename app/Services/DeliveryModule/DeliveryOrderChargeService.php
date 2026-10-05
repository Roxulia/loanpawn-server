<?php

namespace App\Services\DeliveryModule;

use App\Models\DeliveryModule\Delivery;
use App\Repository\DeliveryRepository;
use App\Services\BaseTenantService;

class DeliveryOrderChargeService extends BaseTenantService
{
    public function __construct(private DeliveryRepository $repository) {}

    // Read draft charges for Sales totals and payment limits.
    public function amountForOrder(int $orderId): float
    {
        return (float) ($this->repository->orderCharge($this->resolveCurrentTenantId(), $orderId)?->amount ?? 0);
    }

    // Return fees already recognized on completed deliveries.
    public function recognizedAmountForOrder(int $orderId): float
    {
        return $this->repository->recognizedChargeForOrder($this->resolveCurrentTenantId(), $orderId);
    }

    // Recognize a fee only once when its first Delivery completes.
    public function recognizeForOrder(int $tenantId, int $orderId, int $deliveryId): float
    {
        return $this->repository->recognizeOrderCharge($tenantId, $orderId, $deliveryId);
    }

    public function deliveryById(int $tenantId, int $deliveryId): ?Delivery
    {
        return $this->repository->findById($tenantId, $deliveryId);
    }
}