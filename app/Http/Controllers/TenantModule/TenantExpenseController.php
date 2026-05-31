<?php

namespace App\Http\Controllers\TenantModule;

use App\DataObjects\RequestObjects\TenantExpenseCreate;
use App\DataObjects\RequestObjects\TenantExpenseUpdate;
use App\Http\Controllers\Controller;
use App\Services\TenantModule\TenantExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TenantExpenseController extends Controller
{
    public function __construct(
        private TenantExpenseService $expenseService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->validationFailed($validator);
        }

        $validated = $validator->validated();

        return response()->json([
            'data' => $this->expenseService->list((int) ($validated['per_page'] ?? 15))->toArray(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = array_merge($request->all(), [
            'idempotency_key' => $request->header('Idempotency-Key'),
        ]);

        $validator = Validator::make($input, $this->rules());

        if ($validator->fails()) {
            return $this->validationFailed($validator);
        }

        $validated = $validator->validated();
        $expense = $this->expenseService->createForCurrentTenant(new TenantExpenseCreate(
            description: $validated['description'],
            amount: (float) $validated['amount'],
            expenseTypeId: $validated['expense_type_id'] ?? null,
            idempotencyKey: $validated['idempotency_key'] ?? null,
        ));

        return response()->json([
            'message' => 'Expense created successfully.',
            'data' => $expense->toArray(),
        ], 201);
    }

    public function update(Request $request, string $expenseCode): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->rules(false));

        if ($validator->fails()) {
            return $this->validationFailed($validator);
        }

        $validated = $validator->validated();
        $expense = $this->expenseService->update(new TenantExpenseUpdate(
            expenseId: $this->expenseService->resolveIdByCode($expenseCode),
            code: $expenseCode,
            updateKey: $validated['update_key'] ?? 0,
            description: $validated['description'] ?? null,
            amount: array_key_exists('amount', $validated) ? (float) $validated['amount'] : null,
            expenseTypeId: $validated['expense_type_id'] ?? null,
        ));

        return response()->json([
            'message' => 'Expense updated successfully.',
            'data' => $expense->toArray(),
        ]);
    }

    public function destroy(string $expenseCode): JsonResponse
    {
        $this->expenseService->delete($this->expenseService->resolveIdByCode($expenseCode));

        return response()->json([
            'message' => 'Expense deleted successfully.',
        ]);
    }

    protected function rules(bool $isCreate = true): array
    {
        return [
            'description' => [$isCreate ? 'required' : 'nullable', 'string'],
            'amount' => [$isCreate ? 'required' : 'nullable', 'numeric', 'min:0.01'],
            'expense_type_id' => ['nullable', 'integer'],
            'update_key' => ['nullable', 'integer', 'min:0'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ];
    }

    protected function validationFailed($validator): JsonResponse
    {
        return response()->json([
            'message' => 'Validation failed.',
            'errors' => $validator->errors(),
        ], 422);
    }
}
