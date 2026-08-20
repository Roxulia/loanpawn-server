<?php

namespace App\Http\Controllers\PlatformModule\Admin;

use App\DataObjects\RequestObjects\CorrectExchangeRateRequest;
use App\DataObjects\RequestObjects\StoreExchangeRateRequest;
use App\DataObjects\RequestObjects\VoidExchangeRateRequest;
use App\Http\Controllers\Controller;
use App\Models\CoreModule\ExchangeRateEntry;
use App\Services\PlatformModule\AdminDailyExchangeRateService;
use App\Services\PlatformModule\AdminExchangeRatePairService;
use App\Services\PlatformModule\AdminExchangeRateService;
use App\Utility\MessageCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class AdminExchangeRateController extends Controller
{
    public function __construct(private AdminExchangeRateService $service, private AdminExchangeRatePairService $pairs, private AdminDailyExchangeRateService $daily) {}

    public function index(Request $request): View
    {
        $pairs = $this->pairs->list(100);
        $selectedPair = $pairs->getCollection()->firstWhere('code', strtoupper((string) $request->query('pair_code')))
            ?? $pairs->getCollection()->first();
        $days = in_array((int) $request->query('days'), [7, 30, 90], true)
            ? (int) $request->query('days')
            : 30;
        $trend = $selectedPair
            ? $this->daily->closingTrend((int) $selectedPair->id, $days)
            : [];

        return view('platform.admin.exchange-rates.index', [
            'rates' => $this->service->list(),
            'pairs' => $pairs,
            'selectedPair' => $selectedPair,
            'trendDays' => $days,
            'trend' => $trend,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $exchangeRateRequest = StoreExchangeRateRequest::fromValidated(
            Validator::make($request->all(), StoreExchangeRateRequest::rules())->validate()
        );
        $this->service->create($exchangeRateRequest);

        return back()->with('status', $this->responseMessage(MessageCode::FinancePlatformExchangeRateRecorded));
    }

    public function correct(Request $request, ExchangeRateEntry $entry): RedirectResponse
    {
        $correctionRequest = CorrectExchangeRateRequest::fromValidated(
            Validator::make($request->all(), CorrectExchangeRateRequest::rules())->validate()
        );
        $this->service->correct($entry, $correctionRequest);

        return back()->with('status', $this->responseMessage(MessageCode::FinancePlatformExchangeRateCorrected));
    }

    public function void(Request $request, ExchangeRateEntry $entry): RedirectResponse
    {
        $voidRequest = VoidExchangeRateRequest::fromValidated(
            Validator::make($request->all(), VoidExchangeRateRequest::rules())->validate()
        );
        $this->service->void($entry, $voidRequest);

        return back()->with('status', $this->responseMessage(MessageCode::FinancePlatformExchangeRateVoided));
    }
}
