<?php

namespace App\Http\Controllers\TenantModule\Accounting;

use App\DataObjects\RequestObjects\FinancialAccountTransferCreate;
use App\Http\Controllers\Controller;
use App\Services\TenantModule\Accounting\FinancialAccountTransferService;
use App\Services\TenantModule\FinancialUnitService;
use App\Services\ExchangeRate\ReportingExchangeRateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class FinancialAccountTransferController extends Controller
{
    public function __construct(
        private FinancialAccountTransferService $service,
        private FinancialUnitService $financialUnitService,
        private ReportingExchangeRateService $exchangeRateService,
    ) {}

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
            'from_amount_unit' => ['nullable', 'string', Rule::enum(\App\Enums\FinancialUnit::class), 'exclude_without:from_amount'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'exchange_rate_inversed' => ['nullable', 'boolean'],
            'fee_reporting_exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'fee_reporting_exchange_rate_inversed' => ['nullable', 'boolean'],
            'fee_amount' => ['nullable', 'numeric', 'min:0'],
            'fee_amount_unit' => ['nullable', 'string', Rule::enum(\App\Enums\FinancialUnit::class), 'exclude_without:fee_amount'],
            'note' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ])->validate();

        $resource = $this->service->create(new FinancialAccountTransferCreate(
            fromAccountId: (int) $validated['from_account_id'],
            toAccountId: (int) $validated['to_account_id'],
            fromAmount: $this->financialUnitService->toBase($validated['from_amount'], $validated['from_amount_unit'] ?? null, 9_999_999_999_999_999.9999),
            exchangeRate: $this->exchangeRateService->manualMultiplier(
                isset($validated['exchange_rate']) ? (float) $validated['exchange_rate'] : null,
                (bool) ($validated['exchange_rate_inversed'] ?? false),
            ),
            feeReportingExchangeRate: $this->exchangeRateService->manualMultiplier(
                isset($validated['fee_reporting_exchange_rate']) ? (float) $validated['fee_reporting_exchange_rate'] : null,
                (bool) ($validated['fee_reporting_exchange_rate_inversed'] ?? false),
            ),
            feeAmount: $this->financialUnitService->toBase($validated['fee_amount'] ?? 0, $validated['fee_amount_unit'] ?? null, 9_999_999_999_999_999.9999),
            note: $validated['note'] ?? null,
            idempotencyKey: $validated['idempotency_key'] ?? null,
        ));

        return $this->successResponse($resource->toArray(), 'Financial account transfer completed.', 201);
    }
}
