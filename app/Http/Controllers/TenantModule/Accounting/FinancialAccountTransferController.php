<?php

namespace App\Http\Controllers\TenantModule\Accounting;

use App\DataObjects\RequestObjects\FinancialAccountTransferCreate;
use App\Http\Controllers\Controller;
use App\Services\TenantModule\Accounting\FinancialAccountTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FinancialAccountTransferController extends Controller
{
    public function __construct(private FinancialAccountTransferService $service) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return $this->successResponse($this->service->list((int) ($validated['per_page'] ?? 15))->toArray());
    }

    public function store(Request $request): JsonResponse
    {
        $input = array_merge($request->all(), ['idempotency_key' => $request->header('Idempotency-Key')]);
        $validated = Validator::make($input, [
            'from_account_id' => ['required', 'integer', 'min:1', 'different:to_account_id'],
            'to_account_id' => ['required', 'integer', 'min:1'],
            'from_amount' => ['required', 'numeric', 'gt:0'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'fee_amount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ])->validate();

        $resource = $this->service->create(new FinancialAccountTransferCreate(
            fromAccountId: (int) $validated['from_account_id'],
            toAccountId: (int) $validated['to_account_id'],
            fromAmount: (float) $validated['from_amount'],
            exchangeRate: isset($validated['exchange_rate']) ? (float) $validated['exchange_rate'] : null,
            feeAmount: (float) ($validated['fee_amount'] ?? 0),
            note: $validated['note'] ?? null,
            idempotencyKey: $validated['idempotency_key'] ?? null,
        ));

        return $this->successResponse($resource->toArray(), 'Financial account transfer completed.', 201);
    }
}
