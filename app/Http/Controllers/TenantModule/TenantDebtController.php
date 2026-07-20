<?php

namespace App\Http\Controllers\TenantModule;

use App\DataObjects\RequestObjects\TenantDebtCreate;
use App\DataObjects\RequestObjects\TenantDebtUpdate;
use App\Http\Controllers\Controller;
use App\Services\TenantModule\TenantDebtService;
use App\Utility\MessageCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TenantDebtController extends Controller
{
    public function __construct(
        private TenantDebtService $debtService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();

        return $this->successResponse($this->debtService->list((int) ($validated['per_page'] ?? 15))->toArray());
    }

    public function store(Request $request): JsonResponse
    {
        $input = array_merge($request->all(), [
            'idempotency_key' => $request->header('Idempotency-Key'),
        ]);

        $validator = Validator::make($input, $this->rules());

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();
        $debt = $this->debtService->createExternalDebt(new TenantDebtCreate(
            amount: (float) $validated['amount'],
            description: $validated['description'],
            slipCode: $validated['slip_code'] ?? null,
            customerCode: $validated['customer_code'] ?? null,
            tag: $validated['tag'] ?? null,
            isPaid: (bool) ($validated['is_paid'] ?? false),
            acceptedBy: $validated['accepted_by'] ?? null,
            idempotencyKey: $validated['idempotency_key'] ?? null,
        ));

        return $this->successResponse($debt->toArray(), $this->responseMessage(MessageCode::TenantDebtCreated), 201);
    }

    public function update(Request $request, string $debtCode): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->rules(false));

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();
        $debt = $this->debtService->update(new TenantDebtUpdate(
            debtId: $this->debtService->resolveIdByCode($debtCode),
            code: $debtCode,
            updateKey: $validated['update_key'] ?? 0,
            amount: array_key_exists('amount', $validated) ? (float) $validated['amount'] : null,
            description: $validated['description'] ?? null,
            slipId: $validated['slip_id'] ?? null,
            tag: $validated['tag'] ?? null,
            isPaid: array_key_exists('is_paid', $validated) ? (bool) $validated['is_paid'] : null,
            acceptedBy: $validated['accepted_by'] ?? null,
        ));

        return $this->successResponse($debt->toArray(), $this->responseMessage(MessageCode::TenantDebtUpdated));
    }

    public function destroy(string $debtCode): JsonResponse
    {
        $this->debtService->delete($this->debtService->resolveIdByCode($debtCode));

        return $this->successResponse(message: $this->responseMessage(MessageCode::TenantDebtDeleted));
    }

    public function markAsPaid(Request $request, string $debtCode): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount_paid' => ['required', 'numeric', 'min:0.01'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();
        $debt = $this->debtService->markAsPaid(
            $this->debtService->resolveIdByCode($debtCode),
            (float) $validated['amount_paid'],
        );

        return $this->successResponse($debt, $this->responseMessage(MessageCode::TenantDebtPaid));
    }

    protected function rules(bool $isCreate = true): array
    {
        return [
            'amount' => [$isCreate ? 'required' : 'nullable', 'numeric', 'min:0.01'],
            'description' => [$isCreate ? 'required' : 'nullable', 'string'],
            'slip_code' => ['nullable'],
            'customer_code' => ['nullable'],
            'tag' => ['nullable', 'string', 'max:120'],
            'is_paid' => ['nullable', 'boolean'],
            'accepted_by' => ['nullable', 'integer'],
            'update_key' => ['nullable', 'integer', 'min:0'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ];
    }

}
