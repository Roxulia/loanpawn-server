<?php

namespace App\Http\Controllers\TenantModule\Accounting;

use App\DataObjects\RequestObjects\StoreFinancialAccountRequest;
use App\DataObjects\RequestObjects\UpdateFinancialAccountRequest;
use App\Http\Controllers\Controller;
use App\Services\TenantModule\Accounting\MultiAccountManagement as MultiAccountManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MultiAccountManagement extends Controller
{
    public function __construct(private MultiAccountManagementService $service) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->successResponse($this->service->list(
            (int) ($validated['per_page'] ?? 15),
            $validated['search'] ?? null,
        )->toArray());
    }

    public function store(Request $request): JsonResponse
    {
        $data = StoreFinancialAccountRequest::fromValidated(
            Validator::make($request->all(), StoreFinancialAccountRequest::rules())->validate()
        );

        return $this->successResponse($this->service->create($data)->toArray(), 'Financial account created.', 201);
    }

    public function show(string $accountCode): JsonResponse
    {
        return $this->successResponse($this->service->show($accountCode)->toArray());
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
