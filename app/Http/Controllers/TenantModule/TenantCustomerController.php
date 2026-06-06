<?php

namespace App\Http\Controllers\TenantModule;

use App\DataObjects\RequestObjects\TenantCustomerCreate;
use App\DataObjects\RequestObjects\TenantCustomerUpdate;
use App\Http\Controllers\Controller;
use App\Services\TenantModule\TenantCustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TenantCustomerController extends Controller
{
    public function __construct(
        private TenantCustomerService $tenantCustomerService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();
        $customers = $this->tenantCustomerService->list(
            (int) ($validated['per_page'] ?? 15),
            $validated['search'] ?? null,
        );

        return $this->successResponse($customers->toArray());
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string'],
            'trust_score' => ['nullable', 'integer', 'min:0', 'max:255'],
            'note' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();
        $result = $this->tenantCustomerService->createForCurrentTenant(new TenantCustomerCreate(
            name: $validated['name'],
            email: $validated['email'] ?? null,
            phone: $validated['phone'] ?? null,
            address: $validated['address'] ?? null,
            trustScore: (int) ($validated['trust_score'] ?? 0),
            note: $validated['note'] ?? null,
        ));

        return $this->successResponse(
            $result->toArray(),
            $result->created ? 'Tenant customer created successfully.' : 'Existing tenant customer returned.',
            $result->created ? 201 : 200,
        );
    }

    public function show(string $tenantCustomerCode): JsonResponse
    {
        $customer = $this->tenantCustomerService->showByCode($tenantCustomerCode);

        return $this->successResponse($customer->toArray());
    }

    public function update(Request $request, string $tenantCustomerCode): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string'],
            'trust_score' => ['nullable', 'integer', 'min:0', 'max:255'],
            'note' => ['nullable', 'string'],
            'update_key' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();
        $customer = $this->tenantCustomerService->update(new TenantCustomerUpdate(
            customerId: $this->tenantCustomerService->resolveIdByCode($tenantCustomerCode),
            code: $tenantCustomerCode,
            updateKey: $validated['update_key']??0,
            name: $validated['name'] ?? null,
            email: $validated['email'] ?? null,
            phone: $validated['phone'] ?? null,
            address: $validated['address'] ?? null,
            trustScore: array_key_exists('trust_score', $validated) ? (int) $validated['trust_score'] : null,
            note: $validated['note'] ?? null,
        ));

        return $this->successResponse($customer->toArray(), 'Tenant customer updated successfully.');
    }

    public function destroy(string $tenantCustomerCode): JsonResponse
    {
        $this->tenantCustomerService->delete($this->tenantCustomerService->resolveIdByCode($tenantCustomerCode));

        return $this->successResponse(message: 'Tenant customer deleted successfully.');
    }
}
