<?php

namespace App\Http\Controllers\PawnModule;

use App\DataObjects\RequestObjects\PawnRedemptionCreate;
use App\DataObjects\ResponseObjects\InterestBreakDown;
use App\Http\Controllers\Controller;
use App\Services\PawnModule\PawnRedemptionService;
use App\Utility\MessageCode;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PawnRedemptionController extends Controller
{
    public function __construct(
        private PawnRedemptionService $redemptionService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'start_at' => ['nullable', 'date'],
            'end_at' => ['nullable', 'date', 'after_or_equal:start_at'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();

        return $this->successResponse($this->redemptionService->list(
            (int) ($validated['per_page'] ?? 15),
            isset($validated['start_at']) ? CarbonImmutable::parse($validated['start_at']) : null,
            isset($validated['end_at']) ? CarbonImmutable::parse($validated['end_at']) : null,
        )->toArray());
    }

    public function calculate(string $slipNo): JsonResponse
    {
        return $this->successResponse($this->redemptionService->getRedemptionResultBySlipNo($slipNo)->toArray());
    }

    public function store(Request $request): JsonResponse
    {
        $input = array_merge($request->all(), [
            'idempotency_key' => $request->header('Idempotency-Key'),
        ]);

        $validator = Validator::make($input, [
            'slip_no' => ['required', 'string', 'max:60'],
            'calculated_total' => ['required', 'numeric', 'min:0'],
            'payment_amount' => ['required', 'numeric', 'min:0'],
            'interests' => ['present', 'array'],
            'interests.*.id' => ['required', 'integer', 'min:1'],
            'interests.*.update_key' => ['required', 'integer', 'min:0'],
            'interests.*.interest_amount' => ['required', 'numeric', 'min:0'],
            'interests.*.start_period_at' => ['nullable', 'date'],
            'interests.*.end_period_at' => ['nullable', 'date'],
            'debts' => ['present', 'array'],
            'debts.*.id' => ['required', 'integer', 'min:1'],
            'debts.*.update_key' => ['required', 'integer', 'min:0'],
            'debts.*.amount' => ['required', 'numeric', 'min:0'],
            'redemption_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'created_by' => ['nullable', 'integer'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();
        $redemption = $this->redemptionService->createRedemption(new PawnRedemptionCreate(
            slipNo: $validated['slip_no'],
            calculatedTotal: (float) $validated['calculated_total'],
            paymentAmount: (float) $validated['payment_amount'],
            debts: array_map(
                fn (array $debt): object => (object) [
                    'id' => (int) $debt['id'],
                    'updateKey' => (int) $debt['update_key'],
                    'amount' => (float) $debt['amount'],
                ],
                $validated['debts']
            ),
            interests: array_map(
                fn (array $breakdown): InterestBreakDown => InterestBreakDown::fromValues(
                    id: (int) $breakdown['id'],
                    updateKey: (int) $breakdown['update_key'],
                    interestAmount: (float) $breakdown['interest_amount'],
                    startPeriodAt: $breakdown['start_period_at'] ?? null,
                    endPeriodAt: $breakdown['end_period_at'] ?? null,
                ),
                $validated['interests']
            ),
            redemptionAt: isset($validated['redemption_at']) ? CarbonImmutable::parse($validated['redemption_at']) : null,
            notes: $validated['notes'] ?? null,
            createdBy: $validated['created_by'] ?? null,
            idempotencyKey: $validated['idempotency_key'] ?? null,
        ));

        return $this->successResponse($redemption->toArray(), $this->responseMessage(MessageCode::PawnRedemptionCreated), 201);
    }

    public function show(string $slipNumber): JsonResponse
    {
        return $this->successResponse($this->redemptionService->findBySlipNumber($slipNumber)->toArray());
    }
}
