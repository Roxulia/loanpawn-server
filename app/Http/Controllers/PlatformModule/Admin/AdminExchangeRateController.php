<?php

namespace App\Http\Controllers\PlatformModule\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\CorrectExchangeRateRequest;
use App\Http\Requests\Finance\StoreExchangeRateRequest;
use App\Http\Requests\Finance\VoidExchangeRateRequest;
use App\Models\CoreModule\ExchangeRateEntry;
use App\Services\PlatformModule\AdminDailyExchangeRateService;
use App\Services\PlatformModule\AdminExchangeRatePairService;
use App\Services\PlatformModule\AdminExchangeRateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AdminExchangeRateController extends Controller
{
    public function __construct(private AdminExchangeRateService $service, private AdminExchangeRatePairService $pairs, private AdminDailyExchangeRateService $daily) {}

    public function index(): View
    {
        return view('platform.admin.exchange-rates.index', ['rates' => $this->service->list(), 'pairs' => $this->pairs->list(100), 'dailySummaries' => $this->daily->list()]);
    }

    public function store(StoreExchangeRateRequest $request): RedirectResponse
    {
        $this->service->create($request->validated());

        return back()->with('status', 'Platform exchange rate recorded.');
    }

    public function correct(CorrectExchangeRateRequest $request, ExchangeRateEntry $entry): RedirectResponse
    {
        $this->service->correct($entry, $request->validated('rate'), $request->validated('reason'));

        return back()->with('status', 'Platform exchange rate corrected.');
    }

    public function void(VoidExchangeRateRequest $request, ExchangeRateEntry $entry): RedirectResponse
    {
        $this->service->void($entry, $request->validated('reason'));

        return back()->with('status', 'Platform exchange rate voided.');
    }
}
