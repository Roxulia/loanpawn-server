<?php

namespace App\Services\TenantModule;

use App\Models\PurchasingModule\PurchaseOrder;
use App\Repository\PurchasingRepository;
use App\Services\BaseTenantService;
use App\Services\TableIdGenerationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class PurchasingService extends BaseTenantService
{
    public function __construct(
        private PurchasingRepository $repository,
        private CatalogItemService $catalogItemService,
        private TenantCurrencyService $currencyService,
        private TenantIdempotencyService $idempotencyService,
        private TableIdGenerationService $codeGenerator,
        private PurchaseSupplierService $supplierService,
    ) {}

    public function orders(?string $search = null): array
    {
        return $this->repository->orders($this->resolveCurrentTenantId(), $search)
            ->map(fn (PurchaseOrder $order) => $this->orderResource($order))->all();
    }

    public function order(string $code): array
    {
        $order = $this->repository->orderByCode($this->resolveCurrentTenantId(), $code);
        abort_if($order === null, 404);
        return $this->orderResource($order, true);
    }

    public function createOrder(array $data, ?string $idempotencyKey): array
    {
        return $this->runIdempotent('purchasing.order.create', $data, function (int $tenantId) use ($data): array {
            $supplier = $this->repository->supplierByCode($tenantId, $data['supplier_code']);
            if ($supplier === null || !$supplier->is_active) {
                throw ValidationException::withMessages(['supplier_code' => [__('purchasing.supplier_unavailable')]]);
            }
            $currencyCode = strtoupper($data['currency_code']);
            $this->currencyService->findActiveVisibleByCodeForTenant($tenantId, $currencyCode);
            $order = $this->repository->createOrder($tenantId, [
                'code' => $this->newCode($tenantId, 'purchase_orders'), 'supplier_id' => $supplier->id,
                'status' => 'DRAFT', 'currency_code' => $currencyCode,
                'note' => $data['note'] ?? null, 'created_by' => Auth::id(),
            ]);
            foreach ($data['lines'] as $line) {
                $catalog = null;
                if (!empty($line['catalog_item_code'])) {
                    $catalog = $this->catalogItemService->availableForInventoryByCode($line['catalog_item_code']);
                    if ($catalog === null) {
                        throw ValidationException::withMessages(['lines' => [__('purchasing.catalog_item_unavailable')]]);
                    }
                }
                $trackingMode = $catalog['tracking_mode'] ?? $line['tracking_mode'];
                $this->validateTrackedQuantity($trackingMode, (float) $line['ordered_quantity']);
                $this->repository->createOrderLine($tenantId, $order->id, [
                    'code' => $this->newCode($tenantId, 'purchase_order_lines'),
                    'catalog_item_id' => $catalog['id'] ?? null,
                    'item_description' => trim($line['item_description'] ?? $catalog['name']),
                    'tracking_mode' => $trackingMode,
                    'unit_label' => $line['unit_label'] ?? ($catalog['unit']['name'] ?? __('purchasing.default_unit')),
                    'ordered_quantity' => $line['ordered_quantity'], 'unit_price' => $line['unit_price'],
                ]);
            }
            return $this->orderResource($this->repository->orderByCode($tenantId, $order->code), true);
        }, 201, $idempotencyKey);
    }

    public function transition(string $code, string $action, ?string $idempotencyKey): array
    {
        return $this->runIdempotent('purchasing.order.'.$action, ['order_code' => $code], function (int $tenantId) use ($code, $action): array {
            $order = $this->repository->orderByCode($tenantId, $code, true);
            abort_if($order === null, 404);
            $next = match ([$action, $order->status]) {
                ['order', 'DRAFT'] => 'ORDERED',
                ['confirm', 'ORDERED'] => 'CONFIRMED',
                ['cancel', 'DRAFT'], ['cancel', 'ORDERED'] => 'CANCELLED',
                default => null,
            };
            if ($next === null) {
                throw ValidationException::withMessages(['status' => [__('purchasing.invalid_order_transition')]]);
            }
            if ($action === 'cancel' && $this->repository->orderReceived($tenantId, $order->id) > 0) {
                throw ValidationException::withMessages(['status' => [__('purchasing.cannot_cancel_received')]]);
            }
            $this->repository->updateOrder($order, ['status' => $next, 'ordered_at' => $next === 'ORDERED' ? now()->toDateString() : $order->ordered_at]);
            return $this->orderResource($this->repository->orderByCode($tenantId, $code), true);
        }, 200, $idempotencyKey);
    }

    public function recordPayment(string $orderCode, array $data, ?string $idempotencyKey): array
    {
        return $this->runIdempotent('purchasing.payment.create', ['order_code' => $orderCode] + $data, function (int $tenantId) use ($orderCode, $data): array {
            $order = $this->repository->orderByCode($tenantId, $orderCode, true);
            abort_if($order === null, 404);
            if (in_array($order->status, ['DRAFT', 'CANCELLED'], true)) {
                throw ValidationException::withMessages(['order_code' => [__('purchasing.order_not_payable')]]);
            }
            $orderTotal = $this->orderTotal($order);
            $payments = $this->repository->paymentsForOrder($tenantId, $order->id);
            $netPaid = (float) $payments->sum(fn ($payment) => (float) $payment->amount - (float) $payment->refunds->sum('amount'));
            if ($netPaid + (float) $data['amount'] > $orderTotal + 0.001) {
                throw ValidationException::withMessages(['amount' => [__('purchasing.payment_exceeds_balance')]]);
            }
            $payment = $this->repository->createPayment($tenantId, [
                'code' => $this->newCode($tenantId, 'purchase_payments'), 'purchase_order_id' => $order->id,
                'paid_at' => $data['paid_at'], 'amount' => $data['amount'], 'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null, 'created_by' => Auth::id(),
            ]);
            return $this->paymentResource($payment->load('refunds'));
        }, 201, $idempotencyKey);
    }

    public function recordRefund(string $paymentCode, array $data, ?string $idempotencyKey): array
    {
        return $this->runIdempotent('purchasing.payment.refund', ['payment_code' => $paymentCode] + $data, function (int $tenantId) use ($paymentCode, $data): array {
            $payment = $this->repository->paymentByCode($tenantId, $paymentCode, true);
            if ($payment === null) {
                throw ValidationException::withMessages(['payment_code' => [__('purchasing.payment_not_found')]]);
            }
            $refunded = (float) $payment->refunds->sum('amount');
            if ($refunded + (float) $data['amount'] > (float) $payment->amount + 0.001) {
                throw ValidationException::withMessages(['amount' => [__('purchasing.refund_exceeds_payment')]]);
            }
            $refund = $this->repository->createRefund($tenantId, $payment->id, [
                'code' => $this->newCode($tenantId, 'purchase_refunds'), 'refunded_at' => $data['refunded_at'],
                'amount' => $data['amount'], 'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null, 'created_by' => Auth::id(),
            ]);
            return $this->paymentResource($this->repository->paymentByCode($tenantId, $paymentCode));
        }, 201, $idempotencyKey);
    }

    public function recordReceipt(string $orderCode, array $data, ?string $idempotencyKey): array
    {
        return $this->runIdempotent('purchasing.receipt.create', ['order_code' => $orderCode] + $data, function (int $tenantId) use ($orderCode, $data): array {
            $order = $this->repository->orderByCode($tenantId, $orderCode, true);
            abort_if($order === null, 404);
            if ($order->status !== 'CONFIRMED') {
                throw ValidationException::withMessages(['order_code' => [__('purchasing.order_not_receivable')]]);
            }
            $lineEntries = [];
            foreach ($data['lines'] as $input) {
                $line = $this->repository->lineByCodeForOrder($tenantId, $order->id, $input['purchase_order_line_code'], true);
                if ($line === null) {
                    throw ValidationException::withMessages(['lines' => [__('purchasing.order_line_not_found')]]);
                }
                $this->validateTrackedQuantity($line->tracking_mode, (float) $input['quantity']);
                if ($this->repository->receivedForOrderLine($tenantId, $line->id) + (float) $input['quantity'] > (float) $line->ordered_quantity + 0.001) {
                    throw ValidationException::withMessages(['lines' => [__('purchasing.receipt_exceeds_ordered')]]);
                }
                $lineEntries[] = [$line, (float) $input['quantity']];
            }
            $receipt = $this->repository->createReceipt($tenantId, [
                'code' => $this->newCode($tenantId, 'purchase_receipts'), 'purchase_order_id' => $order->id,
                'received_at' => $data['received_at'], 'note' => $data['note'] ?? null, 'created_by' => Auth::id(),
            ]);
            $lines = [];
            foreach ($lineEntries as [$line, $quantity]) {
                $lines[] = $this->repository->createReceiptLine($tenantId, $receipt->id, $line->id, $quantity,
                    $this->newCode($tenantId, 'purchase_receipt_lines'));
            }
            return $this->receiptResource($receipt, collect($lines));
        }, 201, $idempotencyKey);
    }

    public function recordReturn(string $orderCode, array $data, ?string $idempotencyKey): array
    {
        return $this->runIdempotent('purchasing.return.create', ['order_code' => $orderCode] + $data, function (int $tenantId) use ($orderCode, $data): array {
            $order = $this->repository->orderByCode($tenantId, $orderCode, true);
            abort_if($order === null, 404);
            $lineEntries = [];
            foreach ($data['lines'] as $input) {
                $receiptLine = $this->repository->receiptLineByCode($tenantId, $input['purchase_receipt_line_code'], true);
                if ($receiptLine === null || $receiptLine->orderLine->purchase_order_id !== $order->id) {
                    throw ValidationException::withMessages(['lines' => [__('purchasing.receipt_line_not_found')]]);
                }
                $this->validateTrackedQuantity($receiptLine->orderLine->tracking_mode, (float) $input['quantity']);
                if ($this->repository->returnedForReceiptLine($tenantId, $receiptLine->id) + (float) $input['quantity'] > (float) $receiptLine->received_quantity + 0.001) {
                    throw ValidationException::withMessages(['lines' => [__('purchasing.return_exceeds_received')]]);
                }
                $lineEntries[] = [$receiptLine, (float) $input['quantity']];
            }
            $return = $this->repository->createReturn($tenantId, [
                'code' => $this->newCode($tenantId, 'purchase_returns'), 'purchase_order_id' => $order->id,
                'returned_at' => $data['returned_at'], 'reason' => $data['reason'] ?? null,
                'note' => $data['note'] ?? null, 'created_by' => Auth::id(),
            ]);
            $lines = [];
            foreach ($lineEntries as [$receiptLine, $quantity]) {
                $lines[] = $this->repository->createReturnLine($tenantId, $return->id, $receiptLine->id, $quantity,
                    $this->newCode($tenantId, 'purchase_return_lines'));
            }
            return $this->returnResource($return, collect($lines));
        }, 201, $idempotencyKey);
    }

    public function payments(string $orderCode): array
    {
        $tenantId = $this->resolveCurrentTenantId();
        $order = $this->repository->orderByCode($tenantId, $orderCode);
        abort_if($order === null, 404);
        return $this->repository->paymentsForOrder($tenantId, $order->id)->map(fn ($payment) => $this->paymentResource($payment))->all();
    }

    public function receipts(string $orderCode): array
    {
        $tenantId = $this->resolveCurrentTenantId();
        $order = $this->repository->orderByCode($tenantId, $orderCode);
        abort_if($order === null, 404);
        return $this->repository->receiptsForOrder($tenantId, $order->id)->map(fn ($receipt) => $this->receiptResource($receipt, $receipt->lines))->all();
    }

    public function returns(string $orderCode): array
    {
        $tenantId = $this->resolveCurrentTenantId();
        $order = $this->repository->orderByCode($tenantId, $orderCode);
        abort_if($order === null, 404);
        return $this->repository->returnsForOrder($tenantId, $order->id)->map(fn ($return) => $this->returnResource($return, $return->lines))->all();
    }

    private function orderResource(PurchaseOrder $order, bool $details = false): array
    {
        $tenantId = $this->resolveCurrentTenantId();
        $lines = $order->lines;
        $ordered = (float) $lines->sum(fn ($line) => (float) $line->ordered_quantity);
        $received = $this->repository->orderReceived($tenantId, $order->id);
        $returned = $this->repository->orderReturned($tenantId, $order->id);
        $amount = $this->orderTotal($order);
        $payments = $this->repository->paymentsForOrder($tenantId, $order->id);
        $paid = (float) $payments->sum('amount');
        $refunded = (float) $payments->sum(fn ($payment) => $payment->refunds->sum('amount'));
        $allLinesReceived = $lines->isNotEmpty() && $lines->every(fn ($line) =>
            $this->repository->receivedForOrderLine($tenantId, $line->id) + 0.001 >= (float) $line->ordered_quantity);
        $status = $order->status === 'CONFIRMED' && $allLinesReceived ? 'COMPLETED' : $order->status;
        $resource = [
            'code' => $order->code, 'supplier' => $this->supplierService->resource($order->supplier), 'status' => $status,
            'currency_code' => $order->currency_code, 'ordered_at' => $order->ordered_at?->toDateString(), 'note' => $order->note,
            'totals' => ['line_count' => $lines->count(), 'fully_received_line_count' => $lines->filter(fn ($line) =>
                    $this->repository->receivedForOrderLine($tenantId, $line->id) + 0.001 >= (float) $line->ordered_quantity)->count(),
                'amount' => $this->decimal($amount, 2), 'paid_amount' => $this->decimal($paid, 2),
                'refunded_amount' => $this->decimal($refunded, 2), 'net_paid_amount' => $this->decimal($paid - $refunded, 2)],
            'payment_status' => $amount <= 0 || ($paid - $refunded) + 0.001 >= $amount ? 'PAID' : (($paid - $refunded) <= 0 ? 'UNPAID' : 'PARTIAL'),
            'receipt_status' => $received <= 0 ? 'NOT_RECEIVED' : ($allLinesReceived ? 'RECEIVED' : 'PARTIAL'),
            'return_status' => $returned <= 0 ? 'NOT_RETURNED' : ($returned + 0.001 >= $received ? 'RETURNED' : 'PARTIAL'),
            'created_at' => $order->created_at?->toIso8601String(),
        ];
        if ($details) {
            $resource['lines'] = $lines->map(function ($line) use ($tenantId): array {
                $receivedQuantity = $this->repository->receivedForOrderLine($tenantId, $line->id);
                return ['code' => $line->code, 'catalog_item_code' => $line->catalogItem?->business_code,
                    'item_description' => $line->item_description, 'tracking_mode' => $line->tracking_mode, 'unit_label' => $line->unit_label,
                    'ordered_quantity' => $line->ordered_quantity, 'received_quantity' => $this->decimal($receivedQuantity, 3),
                    'incoming_quantity' => $this->decimal(max(0, (float) $line->ordered_quantity - $receivedQuantity), 3), 'unit_price' => $line->unit_price];
            })->all();
        }
        return $resource;
    }

    private function orderTotal(PurchaseOrder $order): float
    {
        return round((float) $order->lines->sum(fn ($line) => round((float) $line->ordered_quantity * (float) $line->unit_price, 2)), 2);
    }

    private function paymentResource($payment): array
    {
        return ['code' => $payment->code, 'paid_at' => $payment->paid_at?->toDateString(), 'amount' => $payment->amount,
            'reference' => $payment->reference, 'note' => $payment->note,
            'refunded_amount' => $this->decimal((float) $payment->refunds->sum('amount'), 2),
            'refund_status' => $payment->refunds->sum('amount') <= 0 ? 'NOT_REFUNDED' : ((float) $payment->refunds->sum('amount') + 0.001 >= (float) $payment->amount ? 'REFUNDED' : 'PARTIAL'),
            'refunds' => $payment->refunds->map(fn ($refund) => ['code' => $refund->code, 'refunded_at' => $refund->refunded_at?->toDateString(), 'amount' => $refund->amount, 'reference' => $refund->reference, 'note' => $refund->note])->all()];
    }

    private function receiptResource($receipt, $lines): array
    {
        return ['code' => $receipt->code, 'received_at' => $receipt->received_at?->toDateString(), 'note' => $receipt->note,
            'lines' => $lines->map(function ($line): array {
                $orderLine = $line->relationLoaded('orderLine') ? $line->orderLine : $line->orderLine()->first();
                return ['code' => $line->code, 'purchase_order_line_code' => $orderLine?->code, 'quantity' => $line->received_quantity];
            })->all()];
    }

    private function returnResource($return, $lines): array
    {
        return ['code' => $return->code, 'returned_at' => $return->returned_at?->toDateString(), 'reason' => $return->reason,
            'note' => $return->note, 'lines' => $lines->map(function ($line): array {
                $receiptLine = $line->relationLoaded('receiptLine') ? $line->receiptLine : $line->receiptLine()->first();
                return ['code' => $line->code, 'purchase_receipt_line_code' => $receiptLine?->code, 'quantity' => $line->returned_quantity];
            })->all()];
    }

    private function newCode(int $tenantId, string $table): string
    {
        return $this->codeGenerator->generateForTenant($tenantId, $table, CarbonImmutable::now());
    }

    private function decimal(float $value, int $scale): string
    {
        return number_format($value, $scale, '.', '');
    }

    private function validateTrackedQuantity(string $trackingMode, float $quantity): void
    {
        $valid = match ($trackingMode) {
            'UNIQUE' => abs($quantity - 1.0) < 0.0001,
            'SERIALIZED' => floor($quantity) === $quantity,
            default => true,
        };
        if (!$valid) {
            throw ValidationException::withMessages(['quantity' => [__('purchasing.quantity_for_tracking_mode')]]);
        }
    }

    private function runIdempotent(string $operation, array $payload, callable $callback, int $responseCode = 200, ?string $key = null): array
    {
        $tenantId = $this->resolveCurrentTenantId();
        $record = $this->idempotencyService->reserveOptional($operation, $key, $payload);
        if ($record !== null && $this->idempotencyService->isReplay($record)) $this->idempotencyService->replay($record);
        try {
            return DB::transaction(function () use ($callback, $tenantId, $record, $responseCode): array {
                $result = $callback($tenantId);
                if ($record !== null) $this->idempotencyService->markCompleted($record, $responseCode, ['data' => $result]);
                return $result;
            });
        } catch (Throwable $exception) {
            if ($record !== null) $this->idempotencyService->markFailed($record);
            throw $exception;
        }
    }
}
