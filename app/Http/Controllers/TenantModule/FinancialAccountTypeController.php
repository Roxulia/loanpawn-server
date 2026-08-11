<?php

namespace App\Http\Controllers\TenantModule;

use App\DataObjects\RequestObjects\StoreFinancialAccountTypeRequest;
use App\DataObjects\RequestObjects\UpdateFinancialAccountTypeRequest;
use App\Http\Controllers\Controller;
use App\Services\TenantModule\FinancialAccountTypeService;
use App\Utility\MessageCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FinancialAccountTypeController extends Controller
{
    public function __construct(private FinancialAccountTypeService $service) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return $this->successResponse(
            $this->service->list((int) ($validated['per_page'] ?? 15))->toArray()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = StoreFinancialAccountTypeRequest::fromValidated(
            Validator::make($request->all(), StoreFinancialAccountTypeRequest::rules())->validate()
        );

        return $this->successResponse(
            $this->service->create($data)->toArray(),
            $this->responseMessage(MessageCode::FinanceTenantFinancialAccountTypeCreated),
            201,
        );
    }

    public function update(Request $request, string $code): JsonResponse
    {
        $data = UpdateFinancialAccountTypeRequest::fromValidated(
            Validator::make($request->all(), UpdateFinancialAccountTypeRequest::rules())->validate()
        );

        return $this->successResponse(
            $this->service->update($code, $data)->toArray(),
            $this->responseMessage(MessageCode::FinanceTenantFinancialAccountTypeUpdated),
        );
    }

    public function destroy(string $code): JsonResponse
    {
        $this->service->delete($code);

        return $this->successResponse(message: $this->responseMessage(MessageCode::FinanceTenantFinancialAccountTypeDeleted));
    }
}
