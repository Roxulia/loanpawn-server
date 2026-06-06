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
        return $this->successResponse($this->defaultDataService->getMaterialTypes());
    }

    public function getInterestTypes(Request $request)
    {
        return $this->successResponse($this->defaultDataService->getInterestTypes());
    }

    public function getExpenseTypes(Request $request)
    {
        return $this->successResponse($this->defaultDataService->getExpenseTypes());
    }

    public function getExpenseTypeByCode(Request $request, string $code)
    {
        return $this->successResponse($this->defaultDataService->getExpenseTypeByCode($code));
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

        return $this->successResponse($materialType, 'Material type created successfully.', 201);
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

        return $this->successResponse($interestType, 'Interest type created successfully.', 201);
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

        return $this->successResponse($expenseType, 'Expense type created successfully.', 201);
    }

    public function deleteCurrentTenantMaterialType(Request $request, string $code)
    {
        $this->defaultDataService->deleteCurrentTenantMaterialType($code);
        return $this->successResponse(message: 'Material type deleted successfully.');
    }

    public function deleteCurrentTenantInterestType(Request $request, string $code)
    {
        $this->defaultDataService->deleteCurrentTenantInterestType($code);
        return $this->successResponse(message: 'Interest type deleted successfully.');
    }

    public function deleteCurrentTenantExpenseType(Request $request, string $code)
    {
        $this->defaultDataService->deleteCurrentTenantExpenseType($code);
        return $this->successResponse(message: 'Expense type deleted successfully.');
    }
}
