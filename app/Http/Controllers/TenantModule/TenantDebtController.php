<?php

namespace App\Http\Controllers\TenantModule;

use App\DataObjects\RequestObjects\TenantDebtCreate;
use App\DataObjects\RequestObjects\TenantDebtUpdate;
use App\Http\Controllers\Controller;
use App\Services\TenantModule\TenantDebtService;
use App\Services\TenantModule\FinancialUnitService;
use App\Services\ExchangeRate\ReportingExchangeRateService;
use App\Utility\MessageCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class TenantDebtController extends Controller
{
    public function __construct(
        private TenantDebtService $debtService,
        private FinancialUnitService $financialUnitService,
        private ReportingExchangeRateService $exchangeRateService,
    ) {}

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
            amount: $this->financialUnitService->toBase($validated['amount'], $validated['amount_unit'] ?? null, 999_999_999_999.99),
            description: $validated['description'],
            createdAccountId: isset($validated['created_account_id']) ? (int) $validated['created_account_id'] : null,
            reportingExchangeRate: $this->exchangeRateService->manualMultiplier(
                isset($validated['reporting_exchange_rate']) ? (float) $validated['reporting_exchange_rate'] : null,
                (bool) ($validated['reporting_exchange_rate_inversed'] ?? false),
            ),
            slipCode: $validated['slip_code'] ?? null,
            customerCode: $validated['customer_code'] ?? null,
            tag: $validated['tag'] ?? null,
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
            createdAccountId: isset($validated['created_account_id']) ? (int) $validated['created_account_id'] : null,
            reportingExchangeRate: $this->exchangeRateService->manualMultiplier(
                isset($validated['reporting_exchange_rate']) ? (float) $validated['reporting_exchange_rate'] : null,
                (bool) ($validated['reporting_exchange_rate_inversed'] ?? false),
            ),
            amount: array_key_exists('amount', $validated)
                ? $this->financialUnitService->toBase($validated['amount'], $validated['amount_unit'] ?? null, 999_999_999_999.99)
                : null,
            description: $validated['description'] ?? null,
            slipId: $validated['slip_id'] ?? null,
            tag: $validated['tag'] ?? null,
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
            'amount_paid_unit' => ['nullable', 'string', Rule::enum(\App\Enums\FinancialUnit::class), 'exclude_without:amount_paid'],
            'accept_account_id' => ['nullable', 'integer', 'min:1'],
            'reporting_exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'reporting_exchange_rate_inversed' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();
        $debt = $this->debtService->markAsPaid(
            $this->debtService->resolveIdByCode($debtCode),
            $this->financialUnitService->toBase($validated['amount_paid'], $validated['amount_paid_unit'] ?? null, 999_999_999_999.99),
            isset($validated['accept_account_id']) ? (int) $validated['accept_account_id'] : null,
            $this->exchangeRateService->manualMultiplier(
                isset($validated['reporting_exchange_rate']) ? (float) $validated['reporting_exchange_rate'] : null,
                (bool) ($validated['reporting_exchange_rate_inversed'] ?? false),
            ),
        );

        return $this->successResponse($debt, $this->responseMessage(MessageCode::TenantDebtPaid));
    }

    protected function rules(bool $isCreate = true): array
    {
        return [
            'amount' => [$isCreate ? 'required' : 'nullable', 'numeric', 'min:0.01'],
            'amount_unit' => ['nullable', 'string', Rule::enum(\App\Enums\FinancialUnit::class), 'exclude_without:amount'],
            'created_account_id' => ['nullable', 'integer', 'min:1'],
            'reporting_exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'reporting_exchange_rate_inversed' => ['nullable', 'boolean'],
            'description' => [$isCreate ? 'required' : 'nullable', 'string'],
            'slip_code' => ['nullable'],
            'customer_code' => ['nullable'],
            'tag' => ['nullable', 'string', 'max:120'],
            'is_paid' => ['prohibited'],
            'accepted_by' => ['prohibited'],
            'update_key' => ['nullable', 'integer', 'min:0'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ];
    }
}
