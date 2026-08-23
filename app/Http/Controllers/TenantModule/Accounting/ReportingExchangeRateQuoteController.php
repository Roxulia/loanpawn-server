<?php

namespace App\Http\Controllers\TenantModule\Accounting;

use App\Http\Controllers\Controller;
use App\Services\TenantModule\Accounting\ReportingCurrencyRecalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportingExchangeRateQuoteController extends Controller
{
    public function __construct(private ReportingCurrencyRecalculationService $service) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_currency_id' => ['required', 'integer', 'min:1'],
            'to_currency_id' => ['nullable', 'integer', 'min:1'],
        ]);

        return $this->successResponse($this->service->quote(
            (int) $data['from_currency_id'],
            isset($data['to_currency_id']) ? (int) $data['to_currency_id'] : null,
        )->toArray());
    }
}
