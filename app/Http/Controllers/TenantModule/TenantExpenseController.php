<?php

namespace App\Http\Controllers\TenantModule;

use App\DataObjects\RequestObjects\TenantExpenseCreate;
use App\DataObjects\RequestObjects\TenantExpenseUpdate;
use App\Http\Controllers\Controller;
use App\Services\TenantModule\TenantExpenseService;
use App\Utility\MessageCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TenantExpenseController extends Controller
{
    public function __construct(
        private TenantExpenseService $expenseService,
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

        return $this->successResponse($this->expenseService->list((int) ($validated['per_page'] ?? 15))->toArray());
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
        $expense = $this->expenseService->createForCurrentTenant(new TenantExpenseCreate(
            description: $validated['description'],
            amount: (float) $validated['amount'],
            accountId: (int) $validated['account_id'],
            expenseTypeId: $validated['expense_type_id'] ?? null,
            idempotencyKey: $validated['idempotency_key'] ?? null,
            imageReference: $validated['image_reference'] ?? null,
        ));

        return $this->successResponse($expense->toArray(), $this->responseMessage(MessageCode::TenantExpenseCreated), 201);
    }

    public function update(Request $request, string $expenseCode): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->rules(false));

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();
        $expense = $this->expenseService->update(new TenantExpenseUpdate(
            expenseId: $this->expenseService->resolveIdByCode($expenseCode),
            code: $expenseCode,
            updateKey: $validated['update_key'] ?? 0,
            accountId: (int) $validated['account_id'],
            description: $validated['description'] ?? null,
            expenseTypeId: $validated['expense_type_id'] ?? null,
            hasExpenseTypeId: array_key_exists('expense_type_id', $validated),
            imageReference: $validated['image_reference'] ?? null,
            removeImageReference: (bool) ($validated['remove_image_reference'] ?? false),
        ));

        return $this->successResponse($expense->toArray(), $this->responseMessage(MessageCode::TenantExpenseUpdated));
    }

    public function show(string $expenseCode): JsonResponse
    {
        return $this->successResponse($this->expenseService->detail($expenseCode)->toArray());
    }

    public function destroy(string $expenseCode): JsonResponse
    {
        $this->expenseService->delete($this->expenseService->resolveIdByCode($expenseCode));

        return $this->successResponse(message: $this->responseMessage(MessageCode::TenantExpenseDeleted));
    }

    protected function rules(bool $isCreate = true): array
    {
        $rules = [
            'description' => [$isCreate ? 'required' : 'nullable', 'string'],
            'account_id' => ['required', 'integer', 'min:1'],
            'amount' => $isCreate
                ? ['required', 'numeric', 'min:0.01']
                : ['prohibited'],
            'expense_type_id' => ['nullable', 'integer'],
            'update_key' => ['nullable', 'integer', 'min:0'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
            'image_reference' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];

        if (! $isCreate) {
            $rules['remove_image_reference'] = ['nullable', 'boolean', 'prohibits:image_reference'];
        }

        return $rules;
    }
}
