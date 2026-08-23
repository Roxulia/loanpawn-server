<?php

namespace App\Http\Controllers\TenantModule\Accounting;

use App\DataObjects\RequestObjects\StoreFinancialAccountRequest;
use App\DataObjects\RequestObjects\UpdateFinancialAccountRequest;
use App\DataObjects\RequestObjects\FinancialAccountTransactionFilter;
use App\Enums\FinancialAccountTransactionType;
use Carbon\CarbonImmutable;
use App\Http\Controllers\Controller;
use App\Services\TenantModule\Accounting\MultiAccountManagement as MultiAccountManagementService;
use App\Services\TenantModule\FinancialUnitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MultiAccountManagement extends Controller
{
    public function __construct(
        private MultiAccountManagementService $service,
        private FinancialUnitService $financialUnitService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
            'assigned_only' => ['nullable', 'boolean'],
        ]);

        return $this->successResponse($this->service->list(
            (int) ($validated['per_page'] ?? 15),
            $validated['search'] ?? null,
            (bool) ($validated['assigned_only'] ?? false),
        )->toArray());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), StoreFinancialAccountRequest::rules())->validate();
        $validated['balance'] = $this->financialUnitService->toBase(
            $validated['balance'] ?? 0,
            $validated['balance_unit'] ?? null,
            999_999_999_999_999.99,
        );
        $data = StoreFinancialAccountRequest::fromValidated($validated);

        return $this->successResponse($this->service->create($data)->toArray(), 'Financial account created.', 201);
    }

    public function show(string $accountCode): JsonResponse
    {
        return $this->successResponse($this->service->show($accountCode)->toArray());
    }

    public function transactions(Request $request, string $accountCode): JsonResponse
    {
        $types = array_map(fn (FinancialAccountTransactionType $type): string => $type->value, FinancialAccountTransactionType::cases());
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
            'direction' => ['nullable', 'in:debit,credit'],
            'transaction_type' => ['nullable', 'in:'.implode(',', $types)],
            'start_at' => ['nullable', 'date'],
            'end_at' => ['nullable', 'date', 'after_or_equal:start_at'],
        ]);

        $filter = new FinancialAccountTransactionFilter(
            perPage: (int) ($validated['per_page'] ?? 15),
            search: $validated['search'] ?? null,
            direction: $validated['direction'] ?? null,
            transactionType: $validated['transaction_type'] ?? null,
            startAt: isset($validated['start_at']) ? CarbonImmutable::parse($validated['start_at']) : null,
            endAt: isset($validated['end_at']) ? CarbonImmutable::parse($validated['end_at']) : null,
        );

        return $this->successResponse($this->service->transactions($accountCode, $filter)->toArray());
    }

    public function update(Request $request, string $accountCode): JsonResponse
    {
        $data = UpdateFinancialAccountRequest::fromValidated(
            Validator::make($request->all(), UpdateFinancialAccountRequest::rules())->validate()
        );

        return $this->successResponse($this->service->update($accountCode, $data)->toArray(), 'Financial account updated.');
    }

    public function destroy(string $accountCode): JsonResponse
    {
        $this->service->delete($accountCode);

        return $this->successResponse(message: 'Financial account deleted.');
    }
}
