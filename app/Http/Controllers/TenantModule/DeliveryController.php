<?php

namespace App\Http\Controllers\TenantModule;

use App\Http\Controllers\Controller;
use App\Services\DeliveryModule\DeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DeliveryController extends Controller
{
    public function __construct(private DeliveryService $deliveryService) {}

    public function index(): JsonResponse
    {
        return $this->successResponse($this->deliveryService->deliveries());
    }

    // Schedule multiple reserved sale lines under one shared Delivery record.
    public function store(Request $request): JsonResponse
    {
        return $this->mutate($request, [
            'scheduled_at' => ['nullable', 'date'], 'recipient_name' => ['nullable', 'string', 'max:160'],
            'address' => ['nullable', 'string', 'max:500'], 'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'], 'items' => ['required', 'array', 'min:1'],
            'items.*.sales_order_code' => ['required', 'string', 'max:40'],
            'items.*.sales_order_line_code' => ['required', 'string', 'max:40'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
        ], fn (array $data, ?string $key): array => $this->deliveryService->create($data, $key), 201);
    }

    public function dispatch(string $code): JsonResponse
    {
        return $this->successResponse($this->deliveryService->dispatch($code));
    }

    // Confirm actual quantities and post stock and receivable effects.
    public function complete(Request $request, string $code): JsonResponse
    {
        return $this->mutate($request, [
            'delivered_at' => ['required', 'date'], 'items' => ['nullable', 'array'],
            'items.*.delivery_item_code' => ['required_with:items', 'string', 'max:40'],
            'items.*.delivered_quantity' => ['required_with:items', 'numeric', 'gte:0', 'decimal:0,3'],
        ], fn (array $data, ?string $key): array => $this->deliveryService->complete($code, $data, $key));
    }

    // Manage order delivery fees separately from Sales order editing.
    public function setOrderCharge(Request $request, string $orderCode): JsonResponse
    {
        $validator = Validator::make($request->all(), ['amount' => ['required', 'numeric', 'gte:0', 'decimal:0,2']]);
        if ($validator->fails()) return $this->validationErrorResponse($validator->errors());
        return $this->successResponse($this->deliveryService->setOrderCharge($orderCode, (float) $validator->validated()['amount']));
    }

    private function mutate(Request $request, array $rules, callable $operation, int $status = 200): JsonResponse
    {
        $rules['idempotency_key'] = ['nullable', 'string', 'max:120'];
        $input = array_merge($request->all(), ['idempotency_key' => $request->header('Idempotency-Key') ?? $request->input('idempotency_key')]);
        $validator = Validator::make($input, $rules);
        if ($validator->fails()) return $this->validationErrorResponse($validator->errors());
        $data = $validator->validated();
        $key = $data['idempotency_key'] ?? null;
        unset($data['idempotency_key']);
        return $this->successResponse($operation($data, $key), statusCode: $status);
    }
}