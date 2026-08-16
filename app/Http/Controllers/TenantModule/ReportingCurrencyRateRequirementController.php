<?php

namespace App\Http\Controllers\TenantModule;

use App\DataObjects\RequestObjects\HistoricalRateBackfillRequest;
use App\DataObjects\RequestObjects\ReportingCurrencyAbortRequest;
use App\Http\Controllers\Controller;
use App\Services\PlatformModule\TenantServices\TenantSettingService;
use App\Services\TenantModule\Accounting\HistoricalRateBackfillService;
use App\Utility\MessageCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ReportingCurrencyRateRequirementController extends Controller
{
    public function __construct(
        private HistoricalRateBackfillService $backfillService,
        private TenantSettingService $tenantSettingService,
    ) {}

    public function index(): JsonResponse
    {
        return $this->successResponse($this->backfillService->requirements()->toArray());
    }

    public function store(Request $request): JsonResponse
    {
        $data = HistoricalRateBackfillRequest::fromValidated(
            Validator::make($request->all(), HistoricalRateBackfillRequest::rules())->validate(),
        );

        return $this->successResponse(
            $this->backfillService->submit($data)->toArray(),
            $this->responseMessage(MessageCode::FinanceTenantHistoricalRatesRecorded),
        );
    }

    public function abort(Request $request): JsonResponse
    {
        $data = ReportingCurrencyAbortRequest::fromValidated(
            Validator::make($request->all(), ReportingCurrencyAbortRequest::rules())->validate(),
        );

        return $this->successResponse(
            $this->tenantSettingService->abortCurrentReportingCurrencyChange($data)->toArray(),
            $this->responseMessage(MessageCode::FinanceTenantReportingCurrencyChangeAborted),
        );
    }
}
