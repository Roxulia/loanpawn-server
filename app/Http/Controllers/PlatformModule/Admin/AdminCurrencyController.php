<?php

namespace App\Http\Controllers\PlatformModule\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreCurrencyRequest;
use App\Http\Requests\Finance\UpdateCurrencyRequest;
use App\Models\CoreModule\Currency;
use App\Services\PlatformModule\AdminCurrencyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AdminCurrencyController extends Controller
{
    public function __construct(private AdminCurrencyService $service) {}

    public function index(): View
    {
        return view('platform.admin.currencies.index', ['currencies' => $this->service->list()]);
    }

    public function store(StoreCurrencyRequest $request): RedirectResponse
    {
        $this->service->create($request->validated());

        return back()->with('status', 'Default currency created.');
    }

    public function update(UpdateCurrencyRequest $request, Currency $currency): RedirectResponse
    {
        $this->service->update($currency, $request->validated());

        return back()->with('status', 'Default currency updated.');
    }

    public function destroy(Currency $currency): RedirectResponse
    {
        $this->service->delete($currency);

        return back()->with('status', 'Default currency deleted.');
    }
}
