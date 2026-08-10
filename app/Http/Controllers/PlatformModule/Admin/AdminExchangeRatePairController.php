<?php

namespace App\Http\Controllers\PlatformModule\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreExchangeRatePairRequest;
use App\Http\Requests\Finance\UpdateExchangeRatePairRequest;
use App\Models\CoreModule\ExchangeRatePair;
use App\Services\PlatformModule\AdminCurrencyService;
use App\Services\PlatformModule\AdminExchangeRatePairService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AdminExchangeRatePairController extends Controller
{
    public function __construct(private AdminExchangeRatePairService $service, private AdminCurrencyService $currencies) {}

    public function index(): View
    {
        return view('platform.admin.exchange-pairs.index', ['pairs' => $this->service->list(), 'currencies' => $this->currencies->list(100)]);
    }

    public function store(StoreExchangeRatePairRequest $request): RedirectResponse
    {
        $this->service->create($request->validated());

        return back()->with('status', 'Default exchange pair created.');
    }

    public function update(UpdateExchangeRatePairRequest $request, ExchangeRatePair $exchangePair): RedirectResponse
    {
        $this->service->update($exchangePair, $request->validated());

        return back()->with('status', 'Default exchange pair updated.');
    }

    public function destroy(ExchangeRatePair $exchangePair): RedirectResponse
    {
        $this->service->delete($exchangePair);

        return back()->with('status', 'Default exchange pair deleted.');
    }
}
