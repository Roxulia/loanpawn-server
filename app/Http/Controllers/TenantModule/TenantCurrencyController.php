<?php

namespace App\Http\Controllers\TenantModule;

use App\DataObjects\RequestObjects\StoreCurrencyRequest;
use App\DataObjects\RequestObjects\UpdateCurrencyRequest;
use App\DataObjects\ResponseObjects\CurrencyResource;
use App\DataObjects\ResponseObjects\DefaultDataListPage;
use App\Http\Controllers\Controller;
use App\Services\TenantModule\TenantCurrencyService;
use App\Utility\MessageCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TenantCurrencyController extends Controller
{
    public function __construct(private TenantCurrencyService $service) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->service->list($this->perPage($request));

        return $this->successResponse(DefaultDataListPage::fromPaginator($page)->toArray());
    }

    public function show(string $code): JsonResponse
    {
        return $this->successResponse(CurrencyResource::fromModel($this->service->show($code))->toArray());
    }

    public function store(Request $request): JsonResponse
    {
        $currencyRequest = StoreCurrencyRequest::fromValidated(
            Validator::make($request->all(), StoreCurrencyRequest::rules())->validate()
        );

        return $this->successResponse(CurrencyResource::fromModel($this->service->create($currencyRequest))->toArray(), $this->responseMessage(MessageCode::FinanceTenantCurrencyCreated), 201);
    }

    public function update(Request $request, string $code): JsonResponse
    {
        $currencyRequest = UpdateCurrencyRequest::fromValidated(
            Validator::make($request->all(), UpdateCurrencyRequest::rules())->validate()
        );

        return $this->successResponse(CurrencyResource::fromModel($this->service->update($code, $currencyRequest))->toArray(), $this->responseMessage(MessageCode::FinanceTenantCurrencyUpdated));
    }

    public function destroy(string $code): JsonResponse
    {
        $this->service->delete($code);

        return $this->successResponse(message: $this->responseMessage(MessageCode::FinanceTenantCurrencyDeleted));
    }

    private function perPage(Request $request): int
    {
        return max(1, min(100, (int) $request->query('per_page', 50)));
    }
}
