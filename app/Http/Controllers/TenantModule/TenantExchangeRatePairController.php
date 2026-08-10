<?php

namespace App\Http\Controllers\TenantModule;

use App\DataObjects\RequestObjects\StoreExchangeRatePairRequest;
use App\DataObjects\RequestObjects\UpdateExchangeRatePairRequest;
use App\DataObjects\ResponseObjects\ExchangeRatePairResource;
use App\Http\Controllers\Controller;
use App\Services\TenantModule\TenantExchangeRatePairService;
use App\Utility\MessageCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TenantExchangeRatePairController extends Controller
{
    public function __construct(private TenantExchangeRatePairService $service) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->service->list($this->perPage($request));
        $page->through(fn ($row) => ExchangeRatePairResource::fromModel($row)->toArray());

        return $this->successResponse($page);
    }

    public function show(string $code): JsonResponse
    {
        return $this->successResponse(ExchangeRatePairResource::fromModel($this->service->show($code))->toArray());
    }

    public function store(Request $request): JsonResponse
    {
        $exchangeRatePairRequest = StoreExchangeRatePairRequest::fromValidated(
            Validator::make($request->all(), StoreExchangeRatePairRequest::rules())->validate()
        );

        return $this->successResponse(ExchangeRatePairResource::fromModel($this->service->create($exchangeRatePairRequest))->toArray(), $this->responseMessage(MessageCode::FinanceTenantExchangePairCreated), 201);
    }

    public function update(Request $request, string $code): JsonResponse
    {
        $exchangeRatePairRequest = UpdateExchangeRatePairRequest::fromValidated(
            Validator::make($request->all(), UpdateExchangeRatePairRequest::rules())->validate()
        );

        return $this->successResponse(ExchangeRatePairResource::fromModel($this->service->update($code, $exchangeRatePairRequest))->toArray(), $this->responseMessage(MessageCode::FinanceTenantExchangePairUpdated));
    }

    public function destroy(string $code): JsonResponse
    {
        $this->service->delete($code);

        return $this->successResponse(message: $this->responseMessage(MessageCode::FinanceTenantExchangePairDeleted));
    }

    private function perPage(Request $request): int
    {
        return max(1, min(100, (int) $request->query('per_page', 50)));
    }
}
