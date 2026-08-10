<?php

namespace App\Http\Controllers\TenantModule;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreCurrencyRequest;
use App\Http\Requests\Finance\UpdateCurrencyRequest;
use App\Http\Resources\Finance\CurrencyResource;
use App\Services\TenantModule\TenantCurrencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantCurrencyController extends Controller
{
    public function __construct(private TenantCurrencyService $service) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->service->list($this->perPage($request));
        $page->through(fn ($row) => CurrencyResource::make($row)->resolve());

        return $this->successResponse($page);
    }

    public function show(string $code): JsonResponse
    {
        return $this->successResponse(CurrencyResource::make($this->service->show($code))->resolve());
    }

    public function store(StoreCurrencyRequest $request): JsonResponse
    {
        return $this->successResponse(CurrencyResource::make($this->service->create($request->validated()))->resolve(), 'Currency created successfully.', 201);
    }

    public function update(UpdateCurrencyRequest $request, string $code): JsonResponse
    {
        return $this->successResponse(CurrencyResource::make($this->service->update($code, $request->validated()))->resolve(), 'Currency updated successfully.');
    }

    public function destroy(string $code): JsonResponse
    {
        $this->service->delete($code);

        return $this->successResponse(message: 'Currency deleted successfully.');
    }

    private function perPage(Request $request): int
    {
        return max(1, min(100, (int) $request->query('per_page', 50)));
    }
}
