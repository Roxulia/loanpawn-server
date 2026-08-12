<?php

namespace App\Http\Controllers\TenantModule;

use App\DataObjects\RequestObjects\TenantCapitalCreate;
use App\DataObjects\RequestObjects\TenantCapitalUpdate;
use App\Http\Controllers\Controller;
use App\Services\TenantModule\TenantCapitalService;
use App\Utility\MessageCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TenantCapitalController extends Controller
{
    public function __construct(
        private TenantCapitalService $capitalService,
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

        return $this->successResponse($this->capitalService->list((int) ($validated['per_page'] ?? 15))->toArray());
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
        $capital = $this->capitalService->createForCurrentTenant(new TenantCapitalCreate(
            description: $validated['description'],
            amount: (float) $validated['amount'],
            accountId: (int) $validated['account_id'],
            idempotencyKey: $validated['idempotency_key'] ?? null,
        ));

        return $this->successResponse($capital->toArray(), $this->responseMessage(MessageCode::TenantCapitalCreated), 201);
    }

    public function update(Request $request, string $capitalCode): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->rules(false));

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();
        $capital = $this->capitalService->update(new TenantCapitalUpdate(
            capitalId: $this->capitalService->resolveIdByCode($capitalCode),
            code: $capitalCode,
            updateKey: $validated['update_key'] ?? 0,
            accountId: (int) $validated['account_id'],
            description: $validated['description'] ?? null,
            amount: array_key_exists('amount', $validated) ? (float) $validated['amount'] : null,
        ));

        return $this->successResponse($capital->toArray(), $this->responseMessage(MessageCode::TenantCapitalUpdated));
    }

    public function destroy(string $capitalCode): JsonResponse
    {
        $this->capitalService->delete($this->capitalService->resolveIdByCode($capitalCode));

        return $this->successResponse(message: $this->responseMessage(MessageCode::TenantCapitalDeleted));
    }

    protected function rules(bool $isCreate = true): array
    {
        return [
            'description' => [$isCreate ? 'required' : 'nullable', 'string'],
            'account_id' => ['required', 'integer', 'min:1'],
            'amount' => [$isCreate ? 'required' : 'nullable', 'numeric', 'min:0.01'],
            'update_key' => ['nullable', 'integer', 'min:0'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ];
    }
}
