<?php

namespace App\Http\Controllers\PawnModule;

use App\DataObjects\RequestObjects\InterestPaymentAccept;
use App\DataObjects\ResponseObjects\InterestBreakDown;
use App\Http\Controllers\Controller;
use App\Services\PawnModule\InterestFlowService;
use App\Services\TenantModule\FinancialUnitService;
use App\Services\ExchangeRate\ReportingExchangeRateService;
use App\Utility\MessageCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class InterestPaymentController extends Controller
{
    public function __construct(
        private InterestFlowService $interestFlowService,
        private FinancialUnitService $financialUnitService,
        private ReportingExchangeRateService $exchangeRateService,
    ) {}

    public function history(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();
        $history = $this->interestFlowService->getInterestPaymentHistory(
            (int) ($validated['per_page'] ?? 15)
        );

        return $this->successResponse($history->toArray());
    }

    public function calculate(Request $request, string $slipNo): JsonResponse
    {
        $validated = $request->validate([
            'interest_page' => ['nullable', 'integer', 'min:1'],
            'interest_per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return $this->successResponse($this->interestFlowService->calculateInterestBySlipNo(
            $slipNo, (int) ($validated['interest_page'] ?? 1), (int) ($validated['interest_per_page'] ?? 5),
        )->toArray());
    }

    public function pay(Request $request, string $slipNo): JsonResponse
    {
        $input = array_merge($request->all(), [
            'idempotency_key' => $request->header('Idempotency-Key'),
        ]);

        $validator = Validator::make($input, [
            'slip_update_key' => ['required', 'integer', 'min:0'],
            'payment_amount' => ['required', 'numeric', 'min:0.01'],
            'payment_amount_unit' => ['nullable', 'string', Rule::enum(\App\Enums\FinancialUnit::class), 'exclude_without:payment_amount'],
            'accept_account_id' => ['nullable', 'integer', 'min:1'],
            'reporting_exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'reporting_exchange_rate_inversed' => ['nullable', 'boolean'],
            'record_debt' => ['nullable', 'boolean'],
            'interest_breakdown' => ['nullable', 'array', 'present_without:interest_row_versions'],
            'interest_breakdown.*.id' => ['required', 'integer', 'min:1'],
            'interest_breakdown.*.update_key' => ['required', 'integer', 'min:0'],
            'interest_breakdown.*.interest_amount' => ['required', 'numeric', 'min:0'],
            'interest_breakdown.*.start_period_at' => ['nullable', 'date'],
            'interest_breakdown.*.end_period_at' => ['nullable', 'date'],
            'interest_row_versions' => ['nullable', 'array', 'present_without:interest_breakdown'],
            'interest_row_versions.*.id' => ['required', 'integer', 'min:1'],
            'interest_row_versions.*.update_key' => ['required', 'integer', 'min:0'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();

        return $this->successResponse(
            $this->interestFlowService->payInterestBySlipNo(
                $slipNo,
                new InterestPaymentAccept(
                    slipUpdateKey: (int) $validated['slip_update_key'],
                    paymentAmount: $this->financialUnitService->toBase($validated['payment_amount'], $validated['payment_amount_unit'] ?? null, 999_999_999_999.99),
                    acceptAccountId: isset($validated['accept_account_id']) ? (int) $validated['accept_account_id'] : null,
                    reportingExchangeRate: $this->exchangeRateService->manualMultiplier(
                        isset($validated['reporting_exchange_rate']) ? (float) $validated['reporting_exchange_rate'] : null,
                        (bool) ($validated['reporting_exchange_rate_inversed'] ?? false),
                    ),
                    recordDebt: (bool) ($validated['record_debt'] ?? false),
                    interestBreakdown: array_map(
                        fn (array $breakdown): InterestBreakDown => InterestBreakDown::fromValues(
                            id: (int) $breakdown['id'],
                            updateKey: (int) $breakdown['update_key'],
                            interestAmount: (float) ($breakdown['interest_amount'] ?? 0),
                            startPeriodAt: $breakdown['start_period_at'] ?? null,
                            endPeriodAt: $breakdown['end_period_at'] ?? null,
                        ),
                        $validated['interest_row_versions'] ?? $validated['interest_breakdown']
                    ),
                    idempotencyKey: $validated['idempotency_key'] ?? null,
                ),
            ),
            $this->responseMessage(MessageCode::PawnInterestPaymentCreated),
        );
    }
}
