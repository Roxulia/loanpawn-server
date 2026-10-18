<?php

namespace App\Http\Controllers\TenantModule;

use App\Http\Controllers\Controller;
use App\Enums\InventoryOperationOwner;
use App\Services\TenantModule\InventoryService;
use App\DataObjects\RequestObjects\InventoryLocationCreate;
use App\DataObjects\RequestObjects\InventoryLocationUpdate;
use App\DataObjects\RequestObjects\InventoryReceiveRequest;
use App\DataObjects\RequestObjects\InventoryMoveRequest;
use App\DataObjects\RequestObjects\InventoryIssueRequest;
use App\DataObjects\RequestObjects\InventoryAdjustmentRequest;
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

    public function measurementUnits(): JsonResponse
    {
        return $this->successResponse($this->inventoryService->measurementUnits());
    }

    public function storeLocation(Request $request): JsonResponse
    {
        // Reuse the existing validated Idempotency-Key boundary for location creation.
        return $this->mutate($request, [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(['SHOP', 'STORAGE', 'VAULT', 'DISPLAY', 'LENDER', 'OTHER'])],
            'is_sellable' => ['sometimes', 'boolean']
            ],
            fn (array $data, ?string $key): array => $this->inventoryService->createLocation(
                InventoryLocationCreate::fromValidated($data, $key),
            ),
            201,
        );
    }

    public function updateLocation(Request $request, string $locationCode): JsonResponse
    {
        // Reuse the idempotency header boundary and retain existing partial-edit validation.
        return $this->mutate($request, [
            'update_key' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'type' => ['sometimes', Rule::in(['SHOP', 'STORAGE', 'VAULT', 'DISPLAY', 'LENDER', 'OTHER'])],
            'is_active' => ['sometimes', 'boolean'],
            'is_sellable' => ['sometimes', 'boolean']
            ],
            fn (array $data, ?string $key): array => $this->inventoryService->updateLocationByCode(
                InventoryLocationUpdate::fromValidated($locationCode, $data, $key),
            ),
        );
    }

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), ['search' => ['nullable', 'string', 'max:120']]);
        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }
        return $this->successResponse($this->inventoryService->items($validator->validated()['search'] ?? null));
    }

    public function show(string $itemCode): JsonResponse
    {
        return $this->successResponse($this->inventoryService->detailByCode($itemCode));
    }

    public function movements(string $itemCode): JsonResponse
    {
        return $this->successResponse($this->inventoryService->movementsByCode($itemCode));
    }

    public function receive(Request $request): JsonResponse
    {
        return $this->mutate($request, [
            'inventory_item_code' => ['nullable', 'string', 'max:40', 'required_without:name'],
            'catalog_item_code' => ['nullable', 'string', 'max:40'],
            'name' => ['nullable', 'string', 'max:180', 'required_without:inventory_item_code'],
            'description' => ['nullable', 'string', 'max:255'],
            'tracking_mode' => ['nullable', Rule::in(['UNIQUE', 'SERIALIZED', 'QUANTITY'])],
            'unit_code' => ['nullable', 'string', 'max:40'],
            'location_code' => ['required', 'string', 'max:40'],
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'serialized_item_identifiers' => ['nullable', 'array'],
            'serialized_item_identifiers.*' => ['nullable', 'string', 'max:120', 'distinct'],
            'reason' => ['nullable', 'string', 'max:255'],
            'source_type' => ['nullable', 'string', 'max:120'],
            'source_code' => ['nullable', 'string', 'max:120'],
        ], fn (array $data, ?string $key): array => $this->inventoryService->receive(InventoryReceiveRequest::fromValidated($data, $key), InventoryOperationOwner::PublicInventory), 201);
    }

    public function moveStock(Request $request): JsonResponse
    {
        $rules = $this->movementRules();
        $rules['from_location_code'] = ['required', 'string', 'max:40'];
        $rules['to_location_code'] = ['required', 'string', 'max:40'];
        $rules['update_key'] = ['required', 'integer', 'min:0', 'max:4294967295'];
        unset($rules['location_code']);
        return $this->mutate($request, $rules,
            fn (array $data, ?string $key): array => $this->inventoryService->moveStock(InventoryMoveRequest::fromValidated($data, $key)));
    }

    public function issueStock(Request $request): JsonResponse
    {
        $rules = $this->movementRules();
        $rules['location_code'] = ['required', 'string', 'max:40'];
        unset($rules['from_location_code'], $rules['to_location_code']);
        return $this->mutate($request, $rules,
            fn (array $data, ?string $key): array => $this->inventoryService->issueStock(InventoryIssueRequest::fromValidated($data, $key)));
    }

    public function adjustStock(Request $request): JsonResponse
    {
        $rules = $this->movementRules();
        $rules['location_code'] = ['required', 'string', 'max:40'];
        unset($rules['from_location_code'], $rules['to_location_code']);
        $rules['direction'] = ['required', Rule::in(['IN', 'OUT'])];
        $rules['reason'] = ['required', 'string', 'max:255'];
        $rules['serialized_item_identifiers'] = ['nullable', 'array'];
        $rules['serialized_item_identifiers.*'] = ['nullable', 'string', 'max:120', 'distinct'];
        return $this->mutate($request, $rules,
            fn (array $data, ?string $key): array => $this->inventoryService->adjustStock(InventoryAdjustmentRequest::fromValidated($data, $key)));
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
            'inventory_item_code' => ['required', 'string', 'max:40'],
            'from_location_code' => ['nullable', 'string', 'max:40'],
            'location_code' => ['nullable', 'string', 'max:40'],
            'to_location_code' => ['nullable', 'string', 'max:40'],
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'reason' => ['nullable', 'string', 'max:255'],
            'source_type' => ['nullable', 'string', 'max:120'],
            'source_code' => ['nullable', 'string', 'max:120'],
            'serialized_item_codes' => ['nullable', 'array'],
            'serialized_item_codes.*' => ['string', 'max:40', 'distinct'],
        ];
        return $rules;
    }
}
