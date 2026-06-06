<?php

namespace App\Http\Controllers\TenantModule;

use App\Http\Controllers\Controller;
use App\Services\TenantModule\TenantAccountingService;
use App\Utility\MessageCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TenantAccountingController extends Controller
{
    public function __construct(
        private TenantAccountingService $accountingService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();

        return $this->successResponse($this->accountingService->list((int) ($validated['per_page'] ?? 15))->toArray());
    }

    public function getAccountingLedger(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();
        $startDate = new \Carbon\Carbon($validated['start_date']);
        $endDate = new \Carbon\Carbon($validated['end_date']);

        try {
            $ledger = $this->accountingService->buildAccountingLedger($startDate, $endDate, (int) ($validated['per_page'] ?? 15));
            return $this->successResponse($ledger->toArray());
        } catch (\Exception $e) {
            return $this->errorResponse($this->responseMessage(MessageCode::TenantAccountingLedgerBuildFailed), ['error' => $e->getMessage()], 500);
        }
    }

    public function downloadAccountingLedger(Request $request): StreamedResponse|JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();

        try {
            return $this->accountingService->downloadAccountingLedger(
                new \Carbon\Carbon($validated['start_date']),
                new \Carbon\Carbon($validated['end_date']),
            );
        } catch (\Exception $e) {
            return $this->errorResponse($this->responseMessage(MessageCode::TenantAccountingLedgerDownloadFailed), ['error' => $e->getMessage()], 500);
        }
    }

    public function listIncomingTransactions(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();

        return $this->successResponse($this->accountingService->listIncomingTransactions((int) ($validated['per_page'] ?? 15))->toArray());
    }

    public function listOutgoingTransactions(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();

        return $this->successResponse($this->accountingService->listOutgoingTransactions((int) ($validated['per_page'] ?? 15))->toArray());
    }
}
