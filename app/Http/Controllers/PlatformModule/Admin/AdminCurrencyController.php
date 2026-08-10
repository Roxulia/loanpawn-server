<?php

namespace App\Http\Controllers\PlatformModule\Admin;

use App\DataObjects\RequestObjects\StoreCurrencyRequest;
use App\DataObjects\RequestObjects\UpdateCurrencyRequest;
use App\Http\Controllers\Controller;
use App\Models\CoreModule\Currency;
use App\Services\PlatformModule\AdminCurrencyService;
use App\Utility\MessageCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class AdminCurrencyController extends Controller
{
    public function __construct(private AdminCurrencyService $service) {}

    public function index(): View
    {
        return view('platform.admin.currencies.index', ['currencies' => $this->service->list()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $currencyRequest = StoreCurrencyRequest::fromValidated(
            Validator::make($request->all(), StoreCurrencyRequest::rules())->validate()
        );
        $this->service->create($currencyRequest);

        return back()->with('status', $this->responseMessage(MessageCode::FinancePlatformCurrencyCreated));
    }

    public function update(Request $request, Currency $currency): RedirectResponse
    {
        $currencyRequest = UpdateCurrencyRequest::fromValidated(
            Validator::make($request->all(), UpdateCurrencyRequest::rules())->validate()
        );
        $this->service->update($currency, $currencyRequest);

        return back()->with('status', $this->responseMessage(MessageCode::FinancePlatformCurrencyUpdated));
    }

    public function destroy(Currency $currency): RedirectResponse
    {
        $this->service->delete($currency);

        return back()->with('status', $this->responseMessage(MessageCode::FinancePlatformCurrencyDeleted));
    }
}
