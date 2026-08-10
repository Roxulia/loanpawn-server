<?php

namespace App\Http\Controllers\TenantModule;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreExchangeRatePairRequest;
use App\Http\Requests\Finance\UpdateExchangeRatePairRequest;
use App\Http\Resources\Finance\ExchangeRatePairResource;
use App\Services\TenantModule\TenantExchangeRatePairService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantExchangeRatePairController extends Controller
{
    public function __construct(private TenantExchangeRatePairService $service) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->service->list($this->perPage($request));
        $page->through(fn ($row) => ExchangeRatePairResource::make($row)->resolve());

        return $this->successResponse($page);
    }

    public function show(string $code): JsonResponse
    {
        return $this->successResponse(ExchangeRatePairResource::make($this->service->show($code))->resolve());
    }

    public function store(StoreExchangeRatePairRequest $request): JsonResponse
    {
        return $this->successResponse(ExchangeRatePairResource::make($this->service->create($request->validated()))->resolve(), 'Exchange pair created successfully.', 201);
    }

    public function update(UpdateExchangeRatePairRequest $request, string $code): JsonResponse
    {
        return $this->successResponse(ExchangeRatePairResource::make($this->service->update($code, $request->validated()))->resolve(), 'Exchange pair updated successfully.');
    }

    public function destroy(string $code): JsonResponse
    {
        $this->service->delete($code);

        return $this->successResponse(message: 'Exchange pair deleted successfully.');
    }

    private function perPage(Request $request): int
    {
        return max(1, min(100, (int) $request->query('per_page', 50)));
    }
}
