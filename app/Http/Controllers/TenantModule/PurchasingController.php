<?php

namespace App\Http\Controllers\TenantModule;

use App\Http\Controllers\Controller;
use App\Services\TenantModule\PurchaseSupplierService;
use App\Services\TenantModule\PurchasingService;
use App\Services\TenantModule\SupplierPayableService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchasingController extends Controller
{
    public function __construct(private PurchasingService $purchasing, private PurchaseSupplierService $suppliersService, private SupplierPayableService $payablesService) {}

    public function payables(Request $request): JsonResponse
    {
        $data = $request->validate(['order_code' => ['nullable', 'string', 'max:32']]);
        return $this->successResponse($this->payablesService->list($data['order_code'] ?? null));
    }

    public function createPayablePayment(Request $request, string $payableCode): JsonResponse
    {
        $data = $request->validate(['paid_at' => ['required', 'date_format:Y-m-d'], 'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'], 'financial_account_id' => ['nullable', 'integer', 'min:1'], 'reference' => ['nullable', 'string', 'max:120'], 'note' => ['nullable', 'string', 'max:3000']]);
        return $this->successResponse($this->payablesService->pay($payableCode, $data, $request->header('Idempotency-Key')), statusCode: 201);
    }

    public function suppliers(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        return $this->successResponse($this->suppliersService->suppliers($data['search'] ?? null));
    }

    public function supplier(string $supplierCode): JsonResponse
    {
        return $this->successResponse($this->suppliersService->supplier($supplierCode));
    }

    public function createSupplier(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:INDIVIDUAL,SUPPLIER,SHOP,ONLINE_STORE'],
            'customer_code' => ['nullable', 'prohibited_with:lender_code', 'string', 'max:32'],
            'lender_code' => ['nullable', 'prohibited_with:customer_code', 'string', 'max:32'],
            'name' => ['required_without_all:customer_code,lender_code', 'nullable', 'string', 'max:120'],
            'contact_name' => ['nullable', 'string', 'max:120'], 'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'], 'address' => ['nullable', 'string', 'max:3000'],
            'note' => ['nullable', 'string', 'max:3000'],
        ]);
        return $this->successResponse($this->suppliersService->create($data, $request->header('Idempotency-Key')), statusCode: 201);
    }

    public function updateSupplier(Request $request, string $supplierCode): JsonResponse
    {
        $data = $request->validate([
            'type' => ['sometimes', 'required', 'in:INDIVIDUAL,SUPPLIER,SHOP,ONLINE_STORE'],
            'name' => ['sometimes', 'required', 'string', 'max:120'], 'contact_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:3000'], 'note' => ['nullable', 'string', 'max:3000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        return $this->successResponse($this->suppliersService->update($supplierCode, $data));
    }

    public function orders(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        return $this->successResponse($this->purchasing->orders($data['search'] ?? null));
    }

    public function order(string $orderCode): JsonResponse
    {
        return $this->successResponse($this->purchasing->order($orderCode));
    }

    public function createOrder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'supplier_code' => ['required', 'string', 'max:32'], 'currency_code' => ['required', 'string', 'max:12'],
            'note' => ['nullable', 'string', 'max:3000'], 'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.catalog_item_code' => ['nullable', 'string', 'max:32'],
            'items.*.item_description' => ['required_without:items.*.catalog_item_code', 'nullable', 'string', 'max:255'],
            'items.*.tracking_mode' => ['required_without:items.*.catalog_item_code', 'nullable', 'in:UNIQUE,SERIALIZED,QUANTITY'],
            'items.*.unit_label' => ['required_without:items.*.catalog_item_code', 'nullable', 'string', 'max:80'],
            'items.*.unit_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'items.*.ordered_quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'items.*.unit_price' => ['required', 'numeric', 'gte:0', 'decimal:0,2'],
        ]);
        return $this->successResponse($this->purchasing->createOrder($data, $request->header('Idempotency-Key')), statusCode: 201);
    }

    public function updateOrder(Request $request, string $orderCode): JsonResponse
    {
        $data = $request->validate([
            'supplier_code' => ['required', 'string', 'max:32'], 'currency_code' => ['required', 'string', 'max:12'],
            'note' => ['nullable', 'string', 'max:3000'], 'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.catalog_item_code' => ['nullable', 'string', 'max:32'],
            'items.*.item_description' => ['required_without:items.*.catalog_item_code', 'nullable', 'string', 'max:255'],
            'items.*.tracking_mode' => ['required_without:items.*.catalog_item_code', 'nullable', 'in:UNIQUE,SERIALIZED,QUANTITY'],
            'items.*.unit_label' => ['required_without:items.*.catalog_item_code', 'nullable', 'string', 'max:80'],
            'items.*.unit_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'items.*.ordered_quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'items.*.unit_price' => ['required', 'numeric', 'gte:0', 'decimal:0,2'],
        ]);

        return $this->successResponse($this->purchasing->updateOrder($orderCode, $data));
    }
    public function transition(Request $request, string $orderCode, string $action): JsonResponse
    {
        abort_unless(in_array($action, ['order', 'confirm', 'cancel'], true), 404);
        return $this->successResponse($this->purchasing->transition($orderCode, $action, $request->header('Idempotency-Key')));
    }

    public function payments(string $orderCode): JsonResponse
    {
        return $this->successResponse($this->purchasing->payments($orderCode));
    }

    public function createPayment(Request $request, string $orderCode): JsonResponse
    {
        $data = $request->validate([
            'paid_at' => ['required', 'date_format:Y-m-d'], 'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'financial_account_id' => ['nullable', 'integer', 'min:1'],
            'reference' => ['nullable', 'string', 'max:120'], 'note' => ['nullable', 'string', 'max:3000'],
        ]);
        return $this->successResponse($this->purchasing->recordPayment($orderCode, $data, $request->header('Idempotency-Key')), statusCode: 201);
    }

    public function createRefund(Request $request, string $paymentCode): JsonResponse
    {
        $data = $request->validate([
            'refunded_at' => ['required', 'date_format:Y-m-d'], 'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'reference' => ['nullable', 'string', 'max:120'], 'note' => ['nullable', 'string', 'max:3000'],
        ]);
        return $this->successResponse($this->purchasing->recordRefund($paymentCode, $data, $request->header('Idempotency-Key')), statusCode: 201);
    }

    public function receipts(string $orderCode): JsonResponse
    {
        return $this->successResponse($this->purchasing->receipts($orderCode));
    }

    public function createReceipt(Request $request, string $orderCode): JsonResponse
    {
        $data = $request->validate([
            'received_at' => ['required', 'date_format:Y-m-d'], 'note' => ['nullable', 'string', 'max:3000'],
            'location_code' => ['required', 'string', 'max:32'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.purchase_order_item_code' => ['required', 'string', 'max:32', 'distinct'],
            'items.*.unit_identifiers' => ['sometimes', 'array'],
            'items.*.unit_identifiers.*' => ['string', 'max:120'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
        ]);
        return $this->successResponse($this->purchasing->recordReceipt($orderCode, $data, $request->header('Idempotency-Key')), statusCode: 201);
    }

    public function returns(string $orderCode): JsonResponse
    {
        return $this->successResponse($this->purchasing->returns($orderCode));
    }

    public function createReturn(Request $request, string $orderCode): JsonResponse
    {
        $data = $request->validate([
            'returned_at' => ['required', 'date_format:Y-m-d'], 'reason' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:3000'], 'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.purchase_receipt_item_code' => ['required', 'string', 'max:32', 'distinct'],
            'items.*.location_code' => ['required', 'string', 'max:32'],
            'items.*.inventory_unit_codes' => ['sometimes', 'array'],
            'items.*.inventory_unit_codes.*' => ['string', 'max:32'],
            'items.*.cash_refund_amount' => ['sometimes', 'numeric', 'gte:0', 'decimal:0,2'],
            'items.*.financial_account_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
        ]);
        return $this->successResponse($this->purchasing->recordReturn($orderCode, $data, $request->header('Idempotency-Key')), statusCode: 201);
    }
}
