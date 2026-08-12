<?php

namespace App\Http\Controllers\PawnModule;

use App\DataObjects\RequestObjects\InterestPaymentAccept;
use App\DataObjects\ResponseObjects\InterestBreakDown;
use App\Http\Controllers\Controller;
use App\Services\PawnModule\InterestFlowService;
use App\Utility\MessageCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class InterestPaymentController extends Controller
{
    public function __construct(
        private InterestFlowService $interestFlowService,
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

    public function calculate(string $slipNo): JsonResponse
    {
        return $this->successResponse($this->interestFlowService->calculateInterestBySlipNo($slipNo)->toArray());
    }

    public function pay(Request $request, string $slipNo): JsonResponse
    {
        $input = array_merge($request->all(), [
            'idempotency_key' => $request->header('Idempotency-Key'),
        ]);

        $validator = Validator::make($input, [
            'slip_update_key' => ['required', 'integer', 'min:0'],
            'payment_amount' => ['required', 'numeric', 'min:0.01'],
            'accept_account_id' => ['required', 'integer', 'min:1'],
            'record_debt' => ['nullable', 'boolean'],
            'interest_breakdown' => ['required', 'array'],
            'interest_breakdown.*.id' => ['required', 'integer', 'min:1'],
            'interest_breakdown.*.update_key' => ['required', 'integer', 'min:0'],
            'interest_breakdown.*.interest_amount' => ['required', 'numeric', 'min:0'],
            'interest_breakdown.*.start_period_at' => ['nullable', 'date'],
            'interest_breakdown.*.end_period_at' => ['nullable', 'date'],
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
                    paymentAmount: (float) $validated['payment_amount'],
                    acceptAccountId: (int) $validated['accept_account_id'],
                    recordDebt: (bool) ($validated['record_debt'] ?? false),
                    interestBreakdown: array_map(
                        fn (array $breakdown): InterestBreakDown => InterestBreakDown::fromValues(
                            id: (int) $breakdown['id'],
                            updateKey: (int) $breakdown['update_key'],
                            interestAmount: (float) $breakdown['interest_amount'],
                            startPeriodAt: $breakdown['start_period_at'] ?? null,
                            endPeriodAt: $breakdown['end_period_at'] ?? null,
                        ),
                        $validated['interest_breakdown']
                    ),
                    idempotencyKey: $validated['idempotency_key'] ?? null,
                ),
            ),
            $this->responseMessage(MessageCode::PawnInterestPaymentCreated),
        );
    }
}
