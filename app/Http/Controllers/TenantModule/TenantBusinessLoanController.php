<?php

namespace App\Http\Controllers\TenantModule;

use App\DataObjects\RequestObjects\DebtCompoundScheduleUpdate;
use App\DataObjects\RequestObjects\TenantBusinessLoanCreate;
use App\DataObjects\RequestObjects\TenantBusinessLoanPaymentCreate;
use App\DataObjects\RequestObjects\TenantBusinessLoanUpdate;
use App\Enums\FinancialUnit;
use App\Http\Controllers\Controller;
use App\Services\ExchangeRate\ReportingExchangeRateService;
use App\Services\TenantModule\FinancialUnitService;
use App\Services\TenantModule\TenantBusinessLoanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TenantBusinessLoanController extends Controller
{
    public function __construct(
        private TenantBusinessLoanService $businessLoanService,
        private FinancialUnitService $financialUnitService,
        private ReportingExchangeRateService $exchangeRateService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        // Validation of business loan directory filters
        $data = $request->validate(
            [
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
                'search' => ['nullable', 'string', 'max:120']
            ]);
        return $this->successResponse(
            $this->businessLoanService->list((int) ($data['per_page'] ?? 15),
            $data['search'] ?? null));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $loan = $this->businessLoanService->create(new TenantBusinessLoanCreate(
            amount: $this->financialUnitService->toBase($data['amount'],
            $data['amount_unit'] ?? null, 999_999_999_999.99),
            description: $data['description'],
            lenderCode: $data['lender_code'] ?? null,
            receiptAccountId: isset($data['receipt_account_id']) ? (int) $data['receipt_account_id'] : null,
            tag: $data['tag'] ?? null,
            applyInterest: (bool) ($data['apply_interest'] ?? false),
            interestRate: isset($data['interest_rate']) ? (float) $data['interest_rate'] : null,
            interestTypeId: isset($data['interest_type_id']) ? (int) $data['interest_type_id'] : null,
            reportingExchangeRate: $this->rate($data),
            idempotencyKey: $request->header('Idempotency-Key'),
        ));
        return $this->successResponse($loan->toArray(), statusCode: 201);
    }

    public function show(string $loanCode): JsonResponse
    {
        return $this->successResponse($this->businessLoanService->detail($loanCode)->toArray());
    }

    public function update(Request $request, string $loanCode): JsonResponse
    {
        $data = $request->validate($this->rules(false));
        $loan = $this->businessLoanService->update(
            $loanCode,
            new TenantBusinessLoanUpdate(
                updateKey: (int) $data['update_key'],
                lenderCode: $data['lender_code'] ?? null,
                description: $data['description'],
                tag: $data['tag'] ?? null,
                amount: isset($data['amount']) ? $this->financialUnitService->toBase($data['amount'], $data['amount_unit'] ?? null, 999_999_999_999.99) : null,
                receiptAccountId: isset($data['receipt_account_id']) ? (int) $data['receipt_account_id'] : null,
                reportingExchangeRate: $this->rate($data),
        ));
        return $this->successResponse($loan->toArray());
    }

    public function destroy(string $loanCode): JsonResponse
    {
        $this->businessLoanService->delete($loanCode);
        return $this->successResponse();
    }
    public function interest(string $loanCode): JsonResponse
    {
        return $this->successResponse($this->businessLoanService->calculation($loanCode));
    }
    public function payments(string $loanCode): JsonResponse
    {
        return $this->successResponse($this->businessLoanService->history($loanCode));
    }

    public function pay(Request $request, string $loanCode): JsonResponse
    {
        $data = $request->validate([
            'amount_paid' => ['required', 'numeric', 'min:0.01'],
            'amount_paid_unit' => ['nullable', Rule::enum(FinancialUnit::class)],
            'allocation_order' => ['required', 'in:interest_first,principal_first'],
            'loan_update_key' => ['required', 'integer', 'min:0'],
            'payment_account_id' => ['nullable', 'integer', 'min:1'],
            'reporting_exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'reporting_exchange_rate_inversed' => ['nullable', 'boolean'],
        ]);
        return $this->successResponse(
            $this->businessLoanService->pay(
                $loanCode,
                new TenantBusinessLoanPaymentCreate(
                    amount: $this->financialUnitService->toBase($data['amount_paid'], $data['amount_paid_unit'] ?? null, 999_999_999_999.99),
                    allocationOrder: $data['allocation_order'],
                    loanUpdateKey: (int) $data['loan_update_key'],
                    paymentAccountId: isset($data['payment_account_id']) ? (int) $data['payment_account_id'] : null,
                    reportingExchangeRate: $this->rate($data),
                    idempotencyKey: $request->header('Idempotency-Key'),
        )));
    }

    public function updateCompoundSchedule(Request $request, string $loanCode): JsonResponse
    {
        $data = $request->validate(
            [
                'loan_update_key' => ['required', 'integer', 'min:0'],
                'enabled' => ['required', 'boolean'],
                'compound_every' => ['nullable', 'integer', 'min:1'],
                'compound_every_type' => ['nullable', 'in:Day,Week,Month'],
                'next_compound_at' => ['nullable', 'date']
            ]);
        return $this->successResponse($this->businessLoanService->updateCompoundSchedule(
                $loanCode,
                new DebtCompoundScheduleUpdate(
                    (int) $data['loan_update_key'],
                    (bool) $data['enabled'],
                    $data['compound_every'] ?? null,
                    $data['compound_every_type'] ?? null,
                    $data['next_compound_at'] ?? null)
            )->toArray());
    }

    public function compound(string $loanCode): JsonResponse
    {
        return $this->successResponse($this->businessLoanService->compound($loanCode));
    }

    private function rules(bool $create = true): array
    {
        return [
            'amount' => [$create ? 'required' : 'nullable', 'numeric', 'min:0.01'],
            'amount_unit' => ['nullable', Rule::enum(FinancialUnit::class)],
            'description' => ['required', 'string'],
            'lender_code' => ['nullable', 'string'],
            'receipt_account_id' => ['nullable', 'integer', 'min:1'],
            'tag' => ['nullable', 'string', 'max:120'],
            'apply_interest' => [$create ? 'nullable' : 'prohibited', 'boolean'],
            'interest_rate' => [$create ? 'nullable' : 'prohibited', 'numeric', 'gt:0', 'required_if:apply_interest,true'],
            'interest_type_id' => [$create ? 'nullable' : 'prohibited', 'integer', 'min:1', 'required_if:apply_interest,true'],
            'reporting_exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'reporting_exchange_rate_inversed' => ['nullable', 'boolean'],
            'update_key' => [$create ? 'prohibited' : 'required', 'integer', 'min:0'],
        ];
    }

    private function rate(array $data): ?float
    {
        return $this->exchangeRateService->manualMultiplier(isset($data['reporting_exchange_rate']) ? (float) $data['reporting_exchange_rate'] : null, (bool) ($data['reporting_exchange_rate_inversed'] ?? false));
    }
}
