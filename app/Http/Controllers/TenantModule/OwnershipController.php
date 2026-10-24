<?php

namespace App\Http\Controllers\TenantModule;

use App\DTOs\Ownership\Public\RequestObjects\OwnershipAcquisitionCreate;
use App\DataObjects\RequestObjects\InventoryListFilter;
use App\DTOs\Ownership\Public\RequestObjects\OwnershipListFilter;
use App\DTOs\Ownership\Public\RequestObjects\OwnershipLotFilter;
use App\DTOs\Ownership\Public\RequestObjects\OwnershipMovementFilter;
use App\DTOs\Ownership\Public\RequestObjects\OwnershipCandidateFilter;
use App\DTOs\Ownership\Public\RequestObjects\OwnershipReceiptLinkRequest;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Controller;
use App\Services\TenantModule\OwnershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OwnershipController extends Controller
{
    public function __construct(private OwnershipService $ownershipService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        // Validate bounded filters before reading tenant-scoped Ownership summaries.
        $data = $request->validate($this->paginationRules() + [
            'search' => ['nullable', 'string', 'max:120'],
            'tracking_mode' => ['nullable', 'string', 'in:QUANTITY,UNIQUE,SERIALIZED'],
            'holding' => ['sometimes', 'string', 'in:all,current,depleted'],
        ]);
        return $this->successResponse($this->ownershipService->itemPage(OwnershipListFilter::fromValidated($data))->toArray());
    }

    public function show(string $ownedItemCode): JsonResponse
    {
        // Read current detail without embedding unbounded lots or movement history.
        return $this->successResponse($this->ownershipService->itemDetail($ownedItemCode)->toArray());
    }

    public function lots(Request $request, string $ownedItemCode): JsonResponse
    {
        // Page historical cost layers independently from the item summary.
        $data = $request->validate($this->paginationRules() + [
            'currency_code' => ['nullable', 'string', 'max:10'],
            'holding' => ['sometimes', 'string', 'in:all,current,depleted'],
        ]);
        return $this->successResponse($this->ownershipService->lotPage($ownedItemCode, OwnershipLotFilter::fromValidated($data))->toArray());
    }

    public function movements(Request $request, string $ownedItemCode): JsonResponse
    {
        // Page append-only history in the repository's stable occurrence/ID order.
        $data = $request->validate($this->paginationRules());
        return $this->successResponse($this->ownershipService->movementPage($ownedItemCode, OwnershipMovementFilter::fromValidated($data))->toArray());
    }

    public function receiptLinkCandidates(Request $request, string $ownedItemCode, string $lotCode): JsonResponse
    {
        // Public references select candidates; services verify tenant, item and purchase association.
        $data = $request->validate($this->paginationRules() + [
            'search' => ['nullable', 'string', 'max:120'],
            'purchase_receipt_line_code' => ['nullable', 'string', 'max:40'],
        ]);
        return $this->successResponse($this->ownershipService->receiptLinkCandidates(
            $ownedItemCode, $lotCode, OwnershipCandidateFilter::fromValidated($data),
        )->toArray());
    }

    public function linkReceipt(Request $request, string $ownedItemCode, string $lotCode): JsonResponse
    {
        // Only the header supplies replay identity; privileged payload fields never enter the DTO.
        $data = Validator::make(array_merge($request->all(), [
            'idempotency_key' => $request->header('Idempotency-Key'),
        ]), [
            'purchase_receipt_line_code' => ['required', 'string', 'max:40'],
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'update_key' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'serialized_item_codes' => ['sometimes', 'array', 'list'],
            'serialized_item_codes.*' => ['required', 'string', 'max:40', 'distinct:strict'],
            'idempotency_key' => ['required', 'string', 'max:120', 'regex:/\\S/'],
        ])->validate();
        $command = OwnershipReceiptLinkRequest::fromValidated($data, $data['idempotency_key']);
        return $this->successResponse($this->ownershipService->linkReceipt(
            $ownedItemCode, $lotCode, $command, publicReplay: true,
        )->toArray());
    }

    public function inventoryOptions(Request $request): JsonResponse
    {
        // Return tenant-scoped Inventory choices through the Ownership permission boundary.
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        return $this->successResponse($this->ownershipService->inventoryOptions(
            InventoryListFilter::fromValidated($data),
        )->toArray());
    }

    public function acquire(Request $request): JsonResponse
    {
        // Validate a manual acquisition and require idempotency for its ledger writes.
        $data = $request->validate([
            'inventory_item_code' => ['required', 'string', 'max:40'],
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'acquired_at' => ['required', 'date'],
            'unit_cost_basis' => ['required', 'numeric', 'min:0'],
            'estimated_unit_value' => ['nullable', 'numeric', 'min:0'],
            'currency_code' => ['required', 'string', 'size:3', 'alpha'],
            'description' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ]);
        $idempotencyKey = $request->header('Idempotency-Key') ?? ($data['idempotency_key'] ?? null);
        unset($data['idempotency_key']);
        return $this->successResponse(
            $this->ownershipService->manualAcquisition(OwnershipAcquisitionCreate::fromValidated($data), $idempotencyKey),
            statusCode: 201,
        );
    }

    public function expiredCollateral(Request $request): JsonResponse
    {
        // Validate the optional search and return eligible tenant collateral.
        $data = $request->validate(['search' => ['nullable', 'string', 'max:120']]);
        return $this->successResponse($this->ownershipService->expiredCollateral($data['search'] ?? null));
    }

    public function transferExpiredCollateral(Request $request, string $collateralCode): JsonResponse
    {
        // Validate editable acquisition values while deriving item identity and quantity server-side.
        $data = $request->validate([
            'acquired_at' => ['required', 'date'],
            'unit_cost_basis' => ['required', 'numeric', 'min:0'],
            'estimated_unit_value' => ['nullable', 'numeric', 'min:0'],
            'currency_code' => ['required', 'string', 'size:3', 'alpha'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
        $idempotencyKey = $request->header('Idempotency-Key');
        return $this->successResponse($this->ownershipService->transferExpiredCollateral(
            $collateralCode,
            new OwnershipAcquisitionCreate(
                inventoryItemCode: '',
                quantity: 0,
                acquiredAt: $data['acquired_at'],
                // Preserve the legacy Pawn JSON-number boundary without adding float arithmetic.
                // Exact string inputs pass through unchanged; existing numeric clients retain their serialized digits.
                unitCostBasis: is_float($data['unit_cost_basis'])
                    ? json_encode($data['unit_cost_basis'], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)
                    : $data['unit_cost_basis'],
                estimatedUnitValue: isset($data['estimated_unit_value']) && is_float($data['estimated_unit_value'])
                    ? json_encode($data['estimated_unit_value'], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)
                    : ($data['estimated_unit_value'] ?? null),
                currencyCode: strtoupper($data['currency_code']),
                description: $data['description'] ?? null,
            ),
            $idempotencyKey,
        ), statusCode: 201);
    }

    private function paginationRules(): array
    {
        // Keep the same bounded page contract across all Ownership reads.
        return [
            'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
