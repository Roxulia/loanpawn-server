<?php

namespace App\Http\Controllers\TenantModule;

use App\Http\Controllers\Controller;
use App\Services\TenantModule\TenantAccountingTransactionService;
use App\Utility\MessageCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Services\TenantModule\Accounting\FinancialMovementService;
use Carbon\CarbonImmutable;

class TenantAccountingController extends Controller
{
    public function __construct(
        private TenantAccountingTransactionService $accountingService,
        private FinancialMovementService $financialMovementService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();

        return $this->successResponse($this->accountingService->list(
            (int) ($validated['per_page'] ?? 15),
            $validated['search'] ?? null,
        )->toArray());
    }

    public function overview(): JsonResponse
    {
        return $this->successResponse($this->accountingService->overview()->toArray());
    }

    public function movements(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after_or_equal:start_at'],
        ])->validate();

        return $this->successResponse($this->financialMovementService->between(
            CarbonImmutable::parse($validated['start_at']),
            CarbonImmutable::parse($validated['end_at']),
        )->toArray());
    }

    public function getAccountingLedger(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after_or_equal:start_at'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();
        $startDate = new \Carbon\Carbon($validated['start_at']);
        $endDate = new \Carbon\Carbon($validated['end_at']);

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
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after_or_equal:start_at'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();

        try {
            return $this->accountingService->downloadAccountingLedger(
                new \Carbon\Carbon($validated['start_at']),
                new \Carbon\Carbon($validated['end_at']),
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
