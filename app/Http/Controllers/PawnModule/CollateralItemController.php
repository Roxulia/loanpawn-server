<?php

namespace App\Http\Controllers\PawnModule;

use App\Http\Controllers\Controller;
use App\Services\PawnModule\CollateralItemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CollateralItemController extends Controller
{
    public function __construct(
        private CollateralItemService $collateralItemService,
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

        return $this->successResponse(message: 'Collateral item deleted successfully.');
    }

}
