<?php

namespace App\Http\Controllers\TenantModule;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\TenantModule\DefaultDataService;
use App\DataObjects\RequestObjects\DefaultDataCreate;
use App\Support\TenantContext;

class DefaultDataController extends Controller
{
    //
    public function __construct(
        private DefaultDataService $defaultDataService,
    ) {
    }

    public function getMaterialTypes(Request $request)
    {
        return response()->json([
            'data' => $this->defaultDataService->getMaterialTypes(),
        ]);
    }

    public function getInterestTypes(Request $request)
    {
        return response()->json([
            'data' => $this->defaultDataService->getInterestTypes(),
        ]);
    }

    public function getExpenseTypes(Request $request)
    {
        return response()->json([
            'data' => $this->defaultDataService->getExpenseTypes(),
        ]);
    }

    public function getExpenseTypeByCode(Request $request, string $code)
    {
        return response()->json([
            'data' => $this->defaultDataService->getExpenseTypeByCode($code),
        ]);
    }

    public function createCurrentTenantMaterialType(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:255|unique:material_types,code,NULL,id,tenant_id,'.app(TenantContext::class)->id(),
        ]);

        $materialType = $this->defaultDataService->createCurrentTenantMaterialType(new DefaultDataCreate(
            name: $data['name'],
            code: $data['code'],
        ));

        return response()->json([
            'message' => 'Material type created successfully.',
            'data' => $materialType,
        ], 201);
    }

    public function createCurrentTenantInterestType(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:255|unique:interest_types,code,NULL,id,tenant_id,'.app(TenantContext::class)->id(),
            'durationInDays' => 'nullable|integer|min:0',
        ]);

        $interestType = $this->defaultDataService->createCurrentTenantInterestType(new DefaultDataCreate(
            name: $data['name'],
            code: $data['code'],
            durationInDays: $data['durationInDays'] ?? null,
        ));

        return response()->json([
            'message' => 'Interest type created successfully.',
            'data' => $interestType,
        ], 201);
    }

    public function createCurrentTenantExpenseType(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:255|unique:expense_types,code,NULL,id,tenant_id,'.app(TenantContext::class)->id(),
        ]);

        $expenseType = $this->defaultDataService->createCurrentTenantExpenseType(new DefaultDataCreate(
            name: $data['name'],
            code: $data['code'],
        ));

        return response()->json([
            'message' => 'Expense type created successfully.',
            'data' => $expenseType,
        ], 201);
    }

    public function deleteCurrentTenantMaterialType(Request $request, string $code)
    {
        $this->defaultDataService->deleteCurrentTenantMaterialType($code);
        return response()->json([
            'message' => 'Material type deleted successfully.',
        ]);
    }

    public function deleteCurrentTenantInterestType(Request $request, string $code)
    {
        $this->defaultDataService->deleteCurrentTenantInterestType($code);
        return response()->json([
            'message' => 'Interest type deleted successfully.',
        ]);
    }

    public function deleteCurrentTenantExpenseType(Request $request, string $code)
    {
        $this->defaultDataService->deleteCurrentTenantExpenseType($code);
        return response()->json([
            'message' => 'Expense type deleted successfully.',
        ]);
    }
}
