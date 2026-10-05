<?php

namespace App\Http\Controllers\TenantModule;

use App\Http\Controllers\Controller;
use App\Services\TenantModule\SalesService;
use App\Services\TenantModule\SaleReceivableService;
use App\Services\TenantModule\SaleReturnService;
use App\Services\TenantModule\SalesReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SalesController extends Controller
{
    public function __construct(private SalesService $salesService, private SaleReceivableService $saleReceivableService, private SaleReturnService $saleReturnService, private SalesReconciliationService $salesReconciliationService) {}

    // List delivery-linked customer balances for the shared Debt screen.
    public function receivables(): JsonResponse
    {
        return $this->successResponse($this->saleReceivableService->list());
    }

    // Return one sale receivable with its payment history.
    public function receivable(string $code): JsonResponse
    {
        return $this->successResponse($this->saleReceivableService->detail($code));
    }

    // Record a partial or complete receivable collection.
    public function receivablePayment(Request $request, string $code): JsonResponse
    {
        return $this->mutate($request, [
            'financial_account_id' => ['required', 'integer', 'min:1'], 'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'paid_at' => ['required', 'date'], 'reference' => ['nullable', 'string', 'max:120'], 'note' => ['nullable', 'string', 'max:1000'],
        ], fn (array $data, ?string $key): array => $this->salesService->recordReceivablePayment($code, $data, $key), 201);
    }

    // List tenant sales orders and validate the optional search term.
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), ['search' => ['nullable', 'string', 'max:120']]);
        if ($validator->fails()) return $this->validationErrorResponse($validator->errors());
        return $this->successResponse($this->salesService->orders($validator->validated()['search'] ?? null));
    }

    // Return one sale with payment, delivery, and receivable details.
    public function show(string $code): JsonResponse
    {
        return $this->successResponse($this->salesService->order($code));
    }

    // Validate and create a draft sale.
    public function store(Request $request): JsonResponse
    {
        return $this->mutate($request, [
            'customer_code' => ['nullable', 'string', 'max:40'], 'currency_code' => ['required', 'string', 'max:10'],
            'sold_at' => ['required', 'date'], 'note' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'], 'items.*.inventory_item_code' => ['required', 'string', 'max:40'],
            'items.*.owned_item_code' => ['required', 'string', 'max:40'], 'items.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'items.*.unit_price' => ['required', 'numeric', 'gte:0', 'decimal:0,2'],
        ], fn (array $data, ?string $key): array => $this->salesService->createOrder($data, $key), 201);
    }

    // Confirm or cancel a sale through the inventory reservation workflow.
    public function transition(Request $request, string $code, string $action): JsonResponse
    {
        if (! in_array($action, ['confirm', 'cancel'], true)) abort(404);
        return $this->mutate($request, [], fn (array $data, ?string $key): array => $this->salesService->transition($code, $action, $key));
    }

    // Record a customer payment or pre-delivery deposit.
    public function payment(Request $request, string $code): JsonResponse
    {
        return $this->mutate($request, [
            'financial_account_id' => ['required', 'integer', 'min:1'], 'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'paid_at' => ['required', 'date'], 'reference' => ['nullable', 'string', 'max:120'], 'note' => ['nullable', 'string', 'max:1000'],
        ], fn (array $data, ?string $key): array => $this->salesService->recordPayment($code, $data, $key), 201);
    }


    // Update the editable contents of a draft Sales order.
    public function update(Request $request, string $code): JsonResponse
    {
        return $this->mutate($request, [
            'customer_code' => ['nullable', 'string', 'max:40'], 'currency_code' => ['required', 'string', 'max:10'],
            'sold_at' => ['required', 'date'], 'note' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'], 'items.*.inventory_item_code' => ['required', 'string', 'max:40'],
            'items.*.owned_item_code' => ['required', 'string', 'max:40'], 'items.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'items.*.unit_price' => ['required', 'numeric', 'gte:0', 'decimal:0,2'],
        ], fn (array $data, ?string $key): array => $this->salesService->updateDraftOrder($code, $data, $key));
    }

    // List the return history for a Sales order.
    public function returns(string $code): JsonResponse
    {
        return $this->successResponse($this->saleReturnService->returns($code));
    }

    // Validate inventory disposition and payment destination for a customer return.
    public function returnSale(Request $request, string $code): JsonResponse
    {
        return $this->mutate($request, [
            'returned_at' => ['required', 'date'], 'note' => ['nullable', 'string', 'max:1000'],
            'financial_account_id' => ['nullable', 'integer', 'min:1'], 'items' => ['required', 'array', 'min:1'],
            'items.*.delivery_allocation_code' => ['required', 'string', 'max:40'], 'items.*.location_code' => ['required', 'string', 'max:40'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'], 'items.*.disposition' => ['required', 'in:RESTOCK,QUARANTINE'],
            'items.*.inventory_unit_codes' => ['nullable', 'array'], 'items.*.inventory_unit_codes.*' => ['string', 'max:40'],
        ], fn (array $data, ?string $key): array => $this->saleReturnService->create($code, $data, $key), 201);
    }

    // Report missing linked Sales inventory, ownership, and financial records.
    public function reconciliation(): JsonResponse
    {
        return $this->successResponse($this->salesReconciliationService->issues());
    }
    // Validate shared idempotency input and return the service response.
    private function mutate(Request $request, array $rules, callable $operation, int $status = 200): JsonResponse
    {
        $rules['idempotency_key'] = ['nullable', 'string', 'max:120'];
        $input = array_merge($request->all(), ['idempotency_key' => $request->header('Idempotency-Key')]);
        $validator = Validator::make($input, $rules);
        if ($validator->fails()) return $this->validationErrorResponse($validator->errors());
        $data = $validator->validated();
        $key = $data['idempotency_key'] ?? null;
        unset($data['idempotency_key']);
        return $this->successResponse($operation($data, $key), statusCode: $status);
    }
}
