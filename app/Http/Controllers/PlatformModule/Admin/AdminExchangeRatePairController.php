<?php

namespace App\Http\Controllers\PlatformModule\Admin;

use App\DataObjects\RequestObjects\StoreExchangeRatePairRequest;
use App\DataObjects\RequestObjects\UpdateExchangeRatePairRequest;
use App\Http\Controllers\Controller;
use App\Models\CoreModule\ExchangeRatePair;
use App\Services\PlatformModule\AdminCurrencyService;
use App\Services\PlatformModule\AdminExchangeRatePairService;
use App\Utility\MessageCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class AdminExchangeRatePairController extends Controller
{
    public function __construct(private AdminExchangeRatePairService $service, private AdminCurrencyService $currencies) {}

    public function index(): View
    {
        return view('platform.admin.exchange-pairs.index', ['pairs' => $this->service->list(), 'currencies' => $this->currencies->list(100)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $exchangeRatePairRequest = StoreExchangeRatePairRequest::fromValidated(
            Validator::make($request->all(), StoreExchangeRatePairRequest::rules())->validate()
        );
        $this->service->create($exchangeRatePairRequest);

        return back()->with('status', $this->responseMessage(MessageCode::FinancePlatformExchangePairCreated));
    }

    public function update(Request $request, ExchangeRatePair $exchangePair): RedirectResponse
    {
        $exchangeRatePairRequest = UpdateExchangeRatePairRequest::fromValidated(
            Validator::make($request->all(), UpdateExchangeRatePairRequest::rules())->validate()
        );
        $this->service->update($exchangePair, $exchangeRatePairRequest);

        return back()->with('status', $this->responseMessage(MessageCode::FinancePlatformExchangePairUpdated));
    }

    public function destroy(ExchangeRatePair $exchangePair): RedirectResponse
    {
        $this->service->delete($exchangePair);

        return back()->with('status', $this->responseMessage(MessageCode::FinancePlatformExchangePairDeleted));
    }
}
