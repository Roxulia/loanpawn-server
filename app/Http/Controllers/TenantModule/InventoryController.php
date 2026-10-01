<?php

namespace App\Http\Controllers\TenantModule;

use App\Http\Controllers\Controller;
use App\Services\TenantModule\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class InventoryController extends Controller
{
    public function __construct(private InventoryService $inventoryService)
    {
    }

    public function locations(): JsonResponse
    {
        return $this->successResponse($this->inventoryService->locations());
    }

    public function units(): JsonResponse
    {
        return $this->successResponse($this->inventoryService->units());
    }

    public function storeLocation(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(['SHOP', 'STORAGE', 'VAULT', 'DISPLAY', 'LENDER', 'OTHER'])],
        ]);
        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }
        return $this->successResponse($this->inventoryService->createLocation($validator->validated()), statusCode: 201);
    }

    public function updateLocation(Request $request, int $locationId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'type' => ['sometimes', Rule::in(['SHOP', 'STORAGE', 'VAULT', 'DISPLAY', 'LENDER', 'OTHER'])],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }
        return $this->successResponse($this->inventoryService->updateLocation($locationId, $validator->validated()));
    }

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), ['search' => ['nullable', 'string', 'max:120']]);
        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }
        return $this->successResponse($this->inventoryService->items($validator->validated()['search'] ?? null));
    }

    public function show(int $itemId): JsonResponse
    {
        return $this->successResponse($this->inventoryService->detail($itemId));
    }

    public function movements(int $itemId): JsonResponse
    {
        return $this->successResponse($this->inventoryService->movements($itemId));
    }

    public function receive(Request $request): JsonResponse
    {
        return $this->mutate($request, [
            'inventory_item_id' => ['nullable', 'integer', 'min:1', 'required_without:name'],
            'catalog_item_id' => ['nullable', 'integer', 'min:1'],
            'name' => ['nullable', 'string', 'max:180', 'required_without:inventory_item_id'],
            'description' => ['nullable', 'string', 'max:255'],
            'tracking_mode' => ['nullable', Rule::in(['UNIQUE', 'SERIALIZED', 'QUANTITY'])],
            'unit_id' => ['nullable', 'integer', 'min:1'],
            'location_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'unit_identifiers' => ['nullable', 'array'],
            'unit_identifiers.*' => ['nullable', 'string', 'max:120', 'distinct'],
            'reason' => ['nullable', 'string', 'max:255'],
            'source_type' => ['nullable', 'string', 'max:120'],
            'source_id' => ['nullable', 'integer', 'min:1'],
        ], fn (array $data, ?string $key): array => $this->inventoryService->receive($data, $key), 201);
    }

    public function move(Request $request): JsonResponse
    {
        $rules = $this->movementRules();
        $rules['from_location_id'] = ['required', 'integer', 'min:1'];
        $rules['to_location_id'] = ['required', 'integer', 'min:1'];
        unset($rules['location_id']);
        return $this->mutate($request, $rules,
            fn (array $data, ?string $key): array => $this->inventoryService->move($data, $key));
    }

    public function issue(Request $request): JsonResponse
    {
        $rules = $this->movementRules();
        $rules['location_id'] = ['required', 'integer', 'min:1'];
        unset($rules['from_location_id'], $rules['to_location_id']);
        return $this->mutate($request, $rules,
            fn (array $data, ?string $key): array => $this->inventoryService->issue($data, $key));
    }

    public function adjust(Request $request): JsonResponse
    {
        $rules = $this->movementRules();
        $rules['location_id'] = ['required', 'integer', 'min:1'];
        unset($rules['from_location_id'], $rules['to_location_id']);
        $rules['direction'] = ['required', Rule::in(['IN', 'OUT'])];
        $rules['reason'] = ['required', 'string', 'max:255'];
        $rules['unit_identifiers'] = ['nullable', 'array'];
        $rules['unit_identifiers.*'] = ['nullable', 'string', 'max:120', 'distinct'];
        return $this->mutate($request, $rules,
            fn (array $data, ?string $key): array => $this->inventoryService->adjust($data, $key));
    }

    private function mutate(Request $request, array $rules, callable $operation, int $statusCode = 200): JsonResponse
    {
        $rules['idempotency_key'] = ['nullable', 'string', 'max:120'];
        $input = array_merge($request->all(), ['idempotency_key' => $request->header('Idempotency-Key')]);
        $validator = Validator::make($input, $rules);
        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $data = $validator->validated();
        $key = $data['idempotency_key'] ?? null;
        unset($data['idempotency_key']);
        return $this->successResponse($operation($data, $key), statusCode: $statusCode);
    }

    private function movementRules(): array
    {
        $rules = [
            'inventory_item_id' => ['required', 'integer', 'min:1'],
            'from_location_id' => ['nullable', 'integer', 'min:1'],
            'location_id' => ['nullable', 'integer', 'min:1'],
            'to_location_id' => ['nullable', 'integer', 'min:1'],
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'reason' => ['nullable', 'string', 'max:255'],
            'source_type' => ['nullable', 'string', 'max:120'],
            'source_id' => ['nullable', 'integer', 'min:1'],
            'inventory_unit_ids' => ['nullable', 'array'],
            'inventory_unit_ids.*' => ['integer', 'min:1', 'distinct'],
        ];
        return $rules;
    }
}
