<?php

namespace App\Services\DeliveryModule;

use App\Models\DeliveryModule\Delivery;
use App\Repository\DeliveryRepository;
use App\Services\BaseTenantService;
use App\Services\TableIdGenerationService;
use App\Services\TenantModule\SalesService;
use App\Services\TenantModule\TenantIdempotencyService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class DeliveryService extends BaseTenantService
{
    public function __construct(
        private DeliveryRepository $repository,
        private SalesService $salesService,
        private DeliveryOrderChargeService $chargeService,
        private TenantIdempotencyService $idempotencyService,
        private TableIdGenerationService $codeGenerator,
    ) {}

    // List dispatch plans and completed multi-order deliveries for this tenant.
    public function deliveries(): array
    {
        return $this->repository->deliveries($this->resolveCurrentTenantId())->map(fn (Delivery $delivery) => $this->resource($delivery))->all();
    }

    // Set an order-level Delivery charge while the Sales order remains a draft.
    public function setOrderCharge(string $orderCode, float $amount): array
    {
        $order = $this->salesService->orderForDelivery($orderCode);
        if ($order->status !== 'DRAFT') {
            throw ValidationException::withMessages(['sale_code' => ['Delivery charges can only change while the sale is a draft.']]);
        }
        $charge = $this->repository->updateOrderCharge($this->resolveCurrentTenantId(), (int) $order->id, $order->currency_code, $amount);
        return ['sale_code' => $order->code, 'amount' => $charge->amount, 'currency_code' => $charge->currency_code];
    }

    // Schedule one Delivery across multiple confirmed Sales orders and line quantities.
    public function create(array $data, ?string $idempotencyKey): array
    {
        return $this->runIdempotent('delivery.create', $data, function (int $tenantId) use ($data): array {
            $delivery = $this->repository->create($tenantId, [
                'code' => $this->codeGenerator->generateForTenant($tenantId, 'deliveries', CarbonImmutable::now()),
                'status' => 'PLANNED', 'scheduled_at' => $data['scheduled_at'] ?? null, 'delivered_at' => null,
                'recipient_name' => $data['recipient_name'] ?? null, 'address' => $data['address'] ?? null,
                'reference' => $data['reference'] ?? null, 'note' => $data['note'] ?? null,
                'created_by' => Auth::guard('tenantuser')->id(),
            ]);
            $plannedByLine = [];
            foreach ($data['items'] as $item) {
                $order = $this->salesService->orderForDelivery($item['sales_order_code']);
                if ($order->status !== 'CONFIRMED') {
                    throw ValidationException::withMessages(['items' => ['Only confirmed Sales orders can be scheduled for Delivery.']]);
                }
                $line = $order->lines->firstWhere('code', $item['sales_order_line_code']);
                if ($line === null) throw ValidationException::withMessages(['items' => ['The sale line does not belong to the selected order.']]);
                $plannedByLine[$line->id] = ($plannedByLine[$line->id] ?? 0) + (float) $item['quantity'];
                $this->repository->attachOrder($tenantId, $delivery, $order);
                $this->repository->createItem($tenantId, $this->codeGenerator->generateForTenant($tenantId, 'delivery_items', CarbonImmutable::now()), (int) $delivery->id, (int) $line->id, (float) $item['quantity']);
            }
            foreach ($plannedByLine as $lineId => $quantity) {
                $orderLine = collect($data['items'])->first(fn (array $item) => $this->salesService->orderForDelivery($item['sales_order_code'])->lines->contains('id', $lineId));
                if ($orderLine === null) continue;
                $sale = $this->salesService->order($orderLine['sales_order_code']);
                $saleLine = collect($sale['items'])->firstWhere('code', $orderLine['sales_order_line_code']);
                $scheduled = $this->repository->plannedForSalesLine($tenantId, (int) $lineId);
                if ($saleLine === null || $scheduled > (float) $saleLine['reserved_quantity'] + 0.001) {
                    throw ValidationException::withMessages(['items' => ['Scheduled quantity exceeds the confirmed reservation.']]);
                }
            }
            return $this->resource($this->repository->findByCode($tenantId, $delivery->code));
        }, 201, $idempotencyKey);
    }

    // Mark a planned dispatch in transit without issuing inventory before physical delivery.
    public function dispatch(string $code): array
    {
        $tenantId = $this->resolveCurrentTenantId();
        return DB::transaction(function () use ($tenantId, $code): array {
            $delivery = $this->repository->findByCode($tenantId, $code, true);
            abort_if($delivery === null, 404);
            if ($delivery->status !== 'PLANNED') throw ValidationException::withMessages(['status' => ['Only planned deliveries can be dispatched.']]);
            return $this->resource($this->repository->updateStatus($delivery, 'IN_TRANSIT'));
        });
    }

    // Complete a Delivery and let Sales post each order's FIFO stock and receivable effects atomically.
    public function complete(string $code, array $data, ?string $idempotencyKey): array
    {
        return $this->runIdempotent('delivery.complete', ['delivery_code' => $code] + $data, function (int $tenantId) use ($code, $data): array {
            return DB::transaction(function () use ($tenantId, $code, $data): array {
                $delivery = $this->repository->findByCode($tenantId, $code, true);
                if ($delivery === null) abort(404);
                if (! in_array($delivery->status, ['PLANNED', 'IN_TRANSIT'], true)) {
                    throw ValidationException::withMessages(['status' => ['This delivery has already been completed.']]);
                }
                $items = $this->repository->itemsForDelivery($tenantId, (int) $delivery->id, true);
                $actualQuantities = collect($data['items'] ?? [])->keyBy('delivery_item_code');
                $byOrder = [];
                foreach ($items as $item) {
                    $line = $item->salesOrderLine;
                    $actual = (float) ($actualQuantities->get($item->code)['delivered_quantity'] ?? $item->planned_quantity);
                    if ($actual < 0 || $actual > (float) $item->planned_quantity + 0.001) {
                        throw ValidationException::withMessages(['items' => ['Delivered quantity cannot exceed the scheduled quantity.']]);
                    }
                    $this->repository->completeItem($item, $actual);
                    if ($actual > 0.001) {
                        $byOrder[$line->order->code][] = ['sales_order_line_code' => $line->code, 'quantity' => $actual];
                    }
                }
                foreach ($byOrder as $orderCode => $orderItems) {
                    $this->salesService->recordDelivery($orderCode, [
                        'delivery_id' => (int) $delivery->id, 'delivered_at' => $data['delivered_at'],
                        'items' => $orderItems,
                    ], null);
                }
                return $this->resource($this->repository->updateStatus($delivery, 'DELIVERED', $data['delivered_at']));
            });
        }, 200, $idempotencyKey);
    }

    // Build the Delivery API resource without exposing tenant database identifiers.
    private function resource(Delivery $delivery): array
    {
        return [
            'code' => $delivery->code, 'status' => $delivery->status,
            'scheduled_at' => $delivery->scheduled_at?->toDateString(), 'delivered_at' => $delivery->delivered_at?->toDateString(),
            'recipient_name' => $delivery->recipient_name, 'address' => $delivery->address,
            'reference' => $delivery->reference, 'note' => $delivery->note,
            'sales_orders' => $delivery->salesOrders->map(fn ($order) => ['code' => $order->code, 'status' => $order->status, 'customer' => $order->customer?->name])->values()->all(),
            'items' => $delivery->items->map(fn ($item) => [
                'code' => $item->code, 'sales_order_code' => $item->salesOrderLine?->order?->code,
                'sales_order_line_code' => $item->salesOrderLine?->code, 'description' => $item->salesOrderLine?->item_description,
                'planned_quantity' => $item->planned_quantity, 'delivered_quantity' => $item->delivered_quantity,
            ])->values()->all(),
        ];
    }

    private function runIdempotent(string $operation, array $payload, callable $callback, int $status, ?string $key): array
    {
        $tenantId = $this->resolveCurrentTenantId();
        $record = $this->idempotencyService->reserveOptional($operation, $key, $payload);
        if ($record !== null && $this->idempotencyService->isReplay($record)) $this->idempotencyService->replay($record);
        try {
            return DB::transaction(function () use ($callback, $tenantId, $record, $status): array {
                $result = $callback($tenantId);
                if ($record !== null) $this->idempotencyService->markCompleted($record, $status, ['data' => $result]);
                return $result;
            });
        } catch (Throwable $exception) {
            if ($record !== null) $this->idempotencyService->markFailed($record);
            throw $exception;
        }
    }
}