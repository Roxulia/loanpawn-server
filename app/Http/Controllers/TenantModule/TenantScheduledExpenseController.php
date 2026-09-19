<?php

namespace App\Http\Controllers\TenantModule;

use App\DataObjects\RequestObjects\TenantScheduledExpenseWrite;
use App\Http\Controllers\Controller;
use App\Services\TenantModule\FinancialUnitService;
use App\Services\TenantModule\TenantScheduledExpenseService;
use App\Utility\MessageCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class TenantScheduledExpenseController extends Controller
{
    public function __construct(private TenantScheduledExpenseService $service, private FinancialUnitService $financialUnitService) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100'], 'search' => ['nullable', 'string', 'max:120']]);
        return $this->successResponse($this->service->list((int) ($validated['per_page'] ?? 15), $validated['search'] ?? null)->toArray());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request, true);
        return $this->successResponse($this->service->create($this->writeObject($validated))->toArray(), $this->responseMessage(MessageCode::TenantScheduledExpenseCreated), 201);
    }

    public function show(string $code): JsonResponse { return $this->successResponse($this->service->detail($code)->toArray()); }

    public function occurrences(Request $request, string $code): JsonResponse
    {
        $validated = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return $this->successResponse($this->service->occurrenceHistory($code, (int) ($validated['per_page'] ?? 15))->toArray());
    }

    public function update(Request $request, string $code): JsonResponse
    {
        $validated = $this->validated($request, false);
        return $this->successResponse($this->service->update($code, $this->writeObject($validated))->toArray(), $this->responseMessage(MessageCode::TenantScheduledExpenseUpdated));
    }

    public function pause(string $code): JsonResponse { return $this->successResponse($this->service->pause($code)->toArray(), $this->responseMessage(MessageCode::TenantScheduledExpensePaused)); }
    public function resume(string $code): JsonResponse { return $this->successResponse($this->service->resume($code)->toArray(), $this->responseMessage(MessageCode::TenantScheduledExpenseResumed)); }

    public function destroy(string $code): JsonResponse
    {
        $this->service->delete($code);
        return $this->successResponse(message: $this->responseMessage(MessageCode::TenantScheduledExpenseDeleted));
    }

    private function validated(Request $request, bool $creating): array
    {
        $validator = Validator::make($request->all(), [
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'amount_unit' => ['nullable', 'string', Rule::enum(\App\Enums\FinancialUnit::class)],
            'account_id' => ['required', 'integer', 'min:1'],
            'expense_type_id' => ['nullable', 'integer', 'min:1'],
            'recurrence_type' => ['required', Rule::in(['one_time', 'daily', 'weekly', 'monthly'])],
            'start_date' => ['required', 'date_format:Y-m-d', $creating ? 'after_or_equal:today' : 'date'],
            'scheduled_time' => ['required', 'date_format:H:i'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after:start_date', 'prohibited_if:recurrence_type,one_time'],
            'weekly_day' => ['nullable', 'required_if:recurrence_type,weekly', 'integer', 'between:1,7', 'prohibited_unless:recurrence_type,weekly'],
            'monthly_anchor_day' => ['nullable', 'required_if:recurrence_type,monthly', 'integer', 'between:1,31', 'prohibited_unless:recurrence_type,monthly'],
            'update_key' => [$creating ? 'nullable' : 'required', 'integer', 'min:0'],
        ]);
        if ($validator->fails()) { throw new \Illuminate\Validation\ValidationException($validator); }
        return $validator->validated();
    }

    private function writeObject(array $validated): TenantScheduledExpenseWrite
    {
        return new TenantScheduledExpenseWrite(
            description: $validated['description'],
            amount: $this->financialUnitService->toBase($validated['amount'], $validated['amount_unit'] ?? null, 999_999_999_999.99),
            accountId: (int) $validated['account_id'], expenseTypeId: $validated['expense_type_id'] ?? null,
            recurrenceType: $validated['recurrence_type'], startDate: $validated['start_date'], scheduledTime: $validated['scheduled_time'],
            endDate: $validated['end_date'] ?? null, weeklyDay: $validated['weekly_day'] ?? null,
            monthlyAnchorDay: $validated['monthly_anchor_day'] ?? null, updateKey: (int) ($validated['update_key'] ?? 0),
        );
    }
}
