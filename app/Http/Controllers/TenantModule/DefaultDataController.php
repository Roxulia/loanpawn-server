<?php

namespace App\Http\Controllers\TenantModule;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\TenantModule\DefaultDataService;
use App\DataObjects\RequestObjects\DefaultDataCreate;
use App\Support\TenantContext;
use App\Utility\MessageCode;

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

    public function getPaginatedMaterialTypes(Request $request)
    {
        return $this->successResponse(
            $this->defaultDataService->listMaterialTypes($this->paginationPerPage($request))->toArray()
        );
    }

    public function getItemCategoryTypes(Request $request)
    {
        return $this->successResponse($this->defaultDataService->getItemCategoryTypes());
    }

    public function getPaginatedItemCategoryTypes(Request $request)
    {
        return $this->successResponse(
            $this->defaultDataService->listItemCategoryTypes($this->paginationPerPage($request))->toArray()
        );
    }

    public function getInterestTypes(Request $request)
    {
        return $this->successResponse($this->defaultDataService->getInterestTypes());
    }

    public function getPaginatedInterestTypes(Request $request)
    {
        return $this->successResponse(
            $this->defaultDataService->listInterestTypes($this->paginationPerPage($request))->toArray()
        );
    }

    public function getExpenseTypes(Request $request)
    {
        return $this->successResponse($this->defaultDataService->getExpenseTypes());
    }

    public function getPaginatedExpenseTypes(Request $request)
    {
        return $this->successResponse(
            $this->defaultDataService->listExpenseTypes($this->paginationPerPage($request))->toArray()
        );
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

        return $this->successResponse($materialType, $this->responseMessage(MessageCode::TenantMaterialTypeCreated), 201);
    }

    public function createCurrentTenantItemCategoryType(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:255|unique:item_category_types,code,NULL,id,tenant_id,'.app(TenantContext::class)->id(),
        ]);

        $itemCategoryType = $this->defaultDataService->createCurrentTenantItemCategoryType(new DefaultDataCreate(
            name: $data['name'],
            code: $data['code'],
        ));

        return $this->successResponse($itemCategoryType, $this->responseMessage(MessageCode::TenantItemCategoryTypeCreated), 201);
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

        return $this->successResponse($interestType, $this->responseMessage(MessageCode::TenantInterestTypeCreated), 201);
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

        return $this->successResponse($expenseType, $this->responseMessage(MessageCode::TenantExpenseTypeCreated), 201);
    }

    public function deleteCurrentTenantMaterialType(Request $request, string $code)
    {
        $this->defaultDataService->deleteCurrentTenantMaterialType($code);
        return $this->successResponse(message: $this->responseMessage(MessageCode::TenantMaterialTypeDeleted));
    }

    public function deleteCurrentTenantItemCategoryType(Request $request, string $code)
    {
        $this->defaultDataService->deleteCurrentTenantItemCategoryType($code);
        return $this->successResponse(message: $this->responseMessage(MessageCode::TenantItemCategoryTypeDeleted));
    }

    public function deleteCurrentTenantInterestType(Request $request, string $code)
    {
        $this->defaultDataService->deleteCurrentTenantInterestType($code);
        return $this->successResponse(message: $this->responseMessage(MessageCode::TenantInterestTypeDeleted));
    }

    public function deleteCurrentTenantExpenseType(Request $request, string $code)
    {
        $this->defaultDataService->deleteCurrentTenantExpenseType($code);
        return $this->successResponse(message: $this->responseMessage(MessageCode::TenantExpenseTypeDeleted));
    }

    protected function paginationPerPage(Request $request): int
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return (int) ($validated['per_page'] ?? 15);
    }
}
