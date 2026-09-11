<?php

namespace App\Http\Controllers\PawnModule;

use App\Http\Controllers\Controller;
use App\DataObjects\RequestObjects\PawnCollateralItemUpdate;
use App\Services\TenantModule\FinancialUnitService;
use Illuminate\Validation\Rule;
use App\Services\PawnModule\CollateralItemService;
use App\Utility\MessageCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CollateralItemController extends Controller
{
    public function __construct(
        private CollateralItemService $collateralItemService,
        private FinancialUnitService $financialUnitService,
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
        $items = $this->collateralItemService->list(
            (int) ($validated['per_page'] ?? 15),
            $validated['search'] ?? null,
        );

        return $this->successResponse($items->toArray());
    }

    public function show(string $itemCode): JsonResponse
    {
        $item = $this->collateralItemService->showByCode($itemCode);

        return $this->successResponse($item->toArray());
    }

    public function destroy(string $itemCode): JsonResponse
    {
        $this->collateralItemService->delete($this->collateralItemService->resolveIdByCode($itemCode));

        return $this->successResponse(message: $this->responseMessage(MessageCode::PawnCollateralItemDeleted));
    }

    public function update(Request $request, string $itemCode): JsonResponse
    {
        // Nested rows carry IDs for updates and omit them for additions.
        $rules = [
            'update_key' => ['required', 'integer', 'min:0'],
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string'],
            'brand_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'quantity' => ['sometimes', 'required', 'integer', 'min:1', 'max:4294967295'],
            'material_type_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'item_category_type_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'contains_gemstones' => ['sometimes', 'boolean'],
            'gemstone_details' => ['sometimes', 'nullable', 'array:type,weight,quantity,grade'],
            'gemstone_details.type' => ['nullable', 'string'],
            'gemstone_details.weight' => ['nullable', 'string'],
            'gemstone_details.quantity' => ['nullable', 'integer', 'min:1'],
            'gemstone_details.grade' => ['nullable', 'string'],
            'image_reference' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_image' => ['sometimes', 'boolean'],
            'sub_items' => ['sometimes', 'array'],
            'sub_items.*' => ['array:id,name,quantity,kyat,pal,yway,material_type_id,image_reference,remove_image'],
            'sub_items.*.id' => ['sometimes', 'required', 'integer', 'min:1'],
            'sub_items.*.name' => ['sometimes', 'required', 'string', 'max:120'],
            'sub_items.*.quantity' => ['sometimes', 'required', 'integer', 'min:1', 'max:4294967295'],
            'sub_items.*.material_type_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'sub_items.*.image_reference' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'sub_items.*.remove_image' => ['sometimes', 'boolean'],
        ];
        foreach (['kyat', 'pal', 'yway'] as $field) {
            $rules[$field] = ['sometimes', 'required', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'];
            $rules['sub_items.*.'.$field] = ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'];
        }
        foreach (['material_price_per_kyat', 'estimated_value'] as $field) {
            $rules[$field] = ['sometimes', 'required', 'numeric', 'min:0'];
            $rules[$field.'_unit'] = ['sometimes', 'string', Rule::enum(\App\Enums\FinancialUnit::class)];
        }
        foreach (['type', 'code', 'tenant_id', 'loan_contract_id', 'item_status', 'minimum_retail_price', 'image_url'] as $field) {
            $rules[$field] = ['prohibited'];
        }
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }
        $fields = $validator->validated();
        $updateKey = (int) $fields['update_key'];
        unset($fields['update_key']);
        foreach (['material_price_per_kyat', 'estimated_value'] as $field) {
            if (array_key_exists($field, $fields)) {
                $fields[$field] = $this->financialUnitService->toBase($fields[$field], $fields[$field.'_unit'] ?? null, 999_999_999_999.99);
            }
            unset($fields[$field.'_unit']);
        }
        $item = $this->collateralItemService->update(new PawnCollateralItemUpdate(
            itemId: $this->collateralItemService->resolveIdByCode($itemCode),
            updateKey: $updateKey,
            fields: $fields,
        ));
        return $this->successResponse($item->toArray(), message: $this->responseMessage(MessageCode::PawnCollateralItemUpdated));
    }

}
