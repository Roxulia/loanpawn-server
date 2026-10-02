<?php

namespace App\Http\Controllers\TenantModule;

use App\DataObjects\RequestObjects\OwnershipAcquisitionCreate;
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
        // Validate the list filter before delegating tenant-scoped search.
        $data = $request->validate(['search' => ['nullable', 'string', 'max:120']]);
        return $this->successResponse($this->ownershipService->list($data['search'] ?? null));
    }

    public function show(string $ownedItemCode): JsonResponse
    {
        // Return the Ownership aggregate and its cost lots.
        return $this->successResponse($this->ownershipService->detailByCode($ownedItemCode));
    }

    public function movements(string $ownedItemCode): JsonResponse
    {
        // Expose append-only ownership history through a read permission.
        return $this->successResponse($this->ownershipService->movementsByCode($ownedItemCode));
    }

    public function inventoryOptions(Request $request): JsonResponse
    {
        // Return tenant-scoped Inventory choices through the Ownership permission boundary.
        $data = $request->validate(['search' => ['nullable', 'string', 'max:120']]);
        return $this->successResponse($this->ownershipService->inventoryOptions($data['search'] ?? null));
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
}
