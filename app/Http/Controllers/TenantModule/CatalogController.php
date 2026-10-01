<?php

namespace App\Http\Controllers\TenantModule;

use App\DataObjects\RequestObjects\CatalogItemCreate;
use App\Http\Controllers\Controller;
use App\Services\TenantModule\CatalogItemService;
use App\Services\TenantModule\CatalogTaxonomyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function __construct(
        private CatalogItemService $items,
        private CatalogTaxonomyService $taxonomy,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->successResponse(
            $this->items->search($data['search'] ?? null),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer'],
            'sku' => ['nullable', 'string', 'max:100'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'tracking_mode' => ['nullable', 'in:UNIQUE,SERIALIZED,QUANTITY'],
            'unit_id' => ['nullable', 'integer'],
        ]);

        return $this->successResponse(
            $this->items->create(CatalogItemCreate::fromValidated($data)),
            statusCode: 201,
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer'],
            'sku' => ['nullable', 'string', 'max:100'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'tracking_mode' => ['required', 'in:UNIQUE,SERIALIZED,QUANTITY'],
            'unit_id' => ['required', 'integer'],
            'is_active' => ['required', 'boolean'],
            'update_key' => ['required', 'integer', 'min:0'],
        ]);

        return $this->successResponse($this->items->update($id, $data));
    }

    public function categories(): JsonResponse
    {
        return $this->successResponse($this->taxonomy->categories());
    }

    public function units(): JsonResponse
    {
        return $this->successResponse($this->taxonomy->units());
    }

    public function unitsForManagement(): JsonResponse
    {
        return $this->successResponse($this->taxonomy->unitsForManagement());
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        return $this->successResponse(
            $this->taxonomy->createCategory($data['name']),
            statusCode: 201,
        );
    }

    public function updateCategory(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
            'update_key' => ['required', 'integer', 'min:0'],
        ]);

        return $this->successResponse($this->taxonomy->updateCategory($id, $data));
    }

    public function storeUnit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:100'],
            'symbol' => ['nullable', 'string', 'max:30'],
        ]);

        return $this->successResponse(
            $this->taxonomy->createUnit($data),
            statusCode: 201,
        );
    }

    public function updateUnit(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        return $this->successResponse($this->taxonomy->updateUnit($id, $data));
    }
}
