<?php

namespace App\Http\Controllers\TenantModule;

use App\DataObjects\RequestObjects\CorrectExchangeRateRequest;
use App\DataObjects\RequestObjects\StoreExchangeRateRequest;
use App\DataObjects\RequestObjects\VoidExchangeRateRequest;
use App\DataObjects\ResponseObjects\DailyExchangeRateSummaryResource;
use App\DataObjects\ResponseObjects\DefaultDataListPage;
use App\DataObjects\ResponseObjects\ExchangeRateEntryResource;
use App\Http\Controllers\Controller;
use App\Services\TenantModule\TenantDailyExchangeRateService;
use App\Services\TenantModule\TenantExchangeRateService;
use App\Utility\MessageCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TenantExchangeRateController extends Controller
{
    public function __construct(private TenantExchangeRateService $service, private TenantDailyExchangeRateService $daily) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->service->list($this->perPage($request));
        $page->through(fn ($row) => ExchangeRateEntryResource::fromModel($row)->toArray());

        return $this->successResponse(DefaultDataListPage::fromPaginator($page)->toArray());
    }

    public function show(string $code): JsonResponse
    {
        return $this->successResponse(ExchangeRateEntryResource::fromModel($this->service->show($code))->toArray());
    }

    public function store(Request $request): JsonResponse
    {
        $exchangeRateRequest = StoreExchangeRateRequest::fromValidated(
            Validator::make($request->all(), StoreExchangeRateRequest::rules())->validate()
        );

        return $this->successResponse(ExchangeRateEntryResource::fromModel($this->service->create($exchangeRateRequest))->toArray(), $this->responseMessage(MessageCode::FinanceTenantExchangeRateRecorded), 201);
    }

    public function correct(Request $request, string $code): JsonResponse
    {
        $correctionRequest = CorrectExchangeRateRequest::fromValidated(
            Validator::make($request->all(), CorrectExchangeRateRequest::rules())->validate()
        );

        return $this->successResponse(ExchangeRateEntryResource::fromModel($this->service->correct($code, $correctionRequest))->toArray(), $this->responseMessage(MessageCode::FinanceTenantExchangeRateCorrected));
    }

    public function void(Request $request, string $code): JsonResponse
    {
        $voidRequest = VoidExchangeRateRequest::fromValidated(
            Validator::make($request->all(), VoidExchangeRateRequest::rules())->validate()
        );
        $this->service->void($code, $voidRequest);

        return $this->successResponse(message: $this->responseMessage(MessageCode::FinanceTenantExchangeRateVoided));
    }

    public function daily(Request $request): JsonResponse
    {
        $page = $this->daily->list($this->perPage($request));
        $page->through(fn ($row) => DailyExchangeRateSummaryResource::fromModel($row)->toArray());

        return $this->successResponse(DefaultDataListPage::fromPaginator($page)->toArray());
    }

    public function resolve(Request $request): JsonResponse
    {
        $data = $request->validate(['pair_code' => ['required', 'string'], 'date' => ['required', 'date']]);
        $entry = $this->service->resolve($data['pair_code'], $data['date']);

        return $this->successResponse(
            $entry ? ExchangeRateEntryResource::fromModel($entry)->toArray() : null,
            $this->responseMessage($entry ? MessageCode::FinanceTenantExchangeRateResolved : MessageCode::FinanceTenantExchangeRateUnavailable)
        );
    }

    private function perPage(Request $request): int
    {
        return max(1, min(100, (int) $request->query('per_page', 50)));
    }
}
