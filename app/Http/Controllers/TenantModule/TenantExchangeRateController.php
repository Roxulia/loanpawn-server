<?php

namespace App\Http\Controllers\TenantModule;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\CorrectExchangeRateRequest;
use App\Http\Requests\Finance\StoreExchangeRateRequest;
use App\Http\Requests\Finance\VoidExchangeRateRequest;
use App\Http\Resources\Finance\DailyExchangeRateSummaryResource;
use App\Http\Resources\Finance\ExchangeRateEntryResource;
use App\Services\TenantModule\TenantDailyExchangeRateService;
use App\Services\TenantModule\TenantExchangeRateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantExchangeRateController extends Controller
{
    public function __construct(private TenantExchangeRateService $service, private TenantDailyExchangeRateService $daily) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->service->list($this->perPage($request));
        $page->through(fn ($row) => ExchangeRateEntryResource::make($row)->resolve());

        return $this->successResponse($page);
    }

    public function show(string $code): JsonResponse
    {
        return $this->successResponse(ExchangeRateEntryResource::make($this->service->show($code))->resolve());
    }

    public function store(StoreExchangeRateRequest $request): JsonResponse
    {
        return $this->successResponse(ExchangeRateEntryResource::make($this->service->create($request->validated()))->resolve(), 'Exchange rate recorded successfully.', 201);
    }

    public function correct(CorrectExchangeRateRequest $request, string $code): JsonResponse
    {
        return $this->successResponse(ExchangeRateEntryResource::make($this->service->correct($code, $request->validated('rate'), $request->validated('reason')))->resolve(), 'Exchange rate corrected successfully.');
    }

    public function void(VoidExchangeRateRequest $request, string $code): JsonResponse
    {
        $this->service->void($code, $request->validated('reason'));

        return $this->successResponse(message: 'Exchange rate voided successfully.');
    }

    public function daily(Request $request): JsonResponse
    {
        $page = $this->daily->list($this->perPage($request));
        $page->through(fn ($row) => DailyExchangeRateSummaryResource::make($row)->resolve());

        return $this->successResponse($page);
    }

    public function resolve(Request $request): JsonResponse
    {
        $data = $request->validate(['pair_code' => ['required', 'string'], 'date' => ['required', 'date']]);
        $entry = $this->service->resolve($data['pair_code'], $data['date']);

        return $this->successResponse($entry ? ExchangeRateEntryResource::make($entry)->resolve() : null, $entry ? 'Exchange rate resolved.' : 'Exchange rate unavailable.');
    }

    private function perPage(Request $request): int
    {
        return max(1, min(100, (int) $request->query('per_page', 50)));
    }
}
