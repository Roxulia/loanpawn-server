<?php

namespace App\Services\TenantModule\Accounting;

use App\DataObjects\RequestObjects\FinancialAccountTransferCreate;
use App\DataObjects\ResponseObjects\DefaultDataListPage;
use App\DataObjects\ResponseObjects\FinancialAccountTransferResource;
use App\Exceptions\InvalidTenantRequest;
use App\Models\FinancialAccountsTranfers;
use App\Repository\Accounting\FinancialAccountTransferRepository;
use App\Services\BaseTenantService;
use App\Services\TenantModule\TenantAccountingTransactionService;
use App\Services\TenantModule\TenantIdempotencyService;
use App\Services\TenantModule\TenantUserPermissionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

class FinancialAccountTransferService extends BaseTenantService
{
    public function __construct(
        private FinancialAccountTransferRepository $repository,
        private FinancialAccountTransactionService $transactionService,
        private TenantAccountingTransactionService $accountingService,
        private TenantUserPermissionService $permissionService,
        private TenantIdempotencyService $idempotencyService,
    ) {}

    public function list(int $perPage = 15): DefaultDataListPage
    {
        $this->permissionService->authorizePermission('list_financial_account');
        $page = $this->repository->paginate($this->resolveCurrentTenantId(), $perPage);
        $page->through(fn (FinancialAccountsTranfers $transfer): array => FinancialAccountTransferResource::fromModel($transfer)->toArray());

        return DefaultDataListPage::fromPaginator($page);
    }

    public function create(FinancialAccountTransferCreate $request): FinancialAccountTransferResource
    {
        $this->permissionService->authorizePermission('transfer_financial_account');
        if ($request->fromAccountId === $request->toAccountId) {
            throw new InvalidTenantRequest('Source and destination accounts must be different.');
        }
        if ($request->fromAmount <= 0 || $request->feeAmount < 0) {
            throw new InvalidTenantRequest('Transfer amount must be positive and fee cannot be negative.');
        }

        $idempotency = $this->idempotencyService->reserveOptional('financial_account.transfer', $request->idempotencyKey, $request);
        if ($idempotency !== null && $this->idempotencyService->isReplay($idempotency)) {
            $this->idempotencyService->replay($idempotency);
        }

        try {
            $transfer = DB::transaction(function () use ($request): FinancialAccountsTranfers {
                $tenantId = $this->resolveCurrentTenantId();
                $accounts = $this->repository->lockAccounts($tenantId, [$request->fromAccountId, $request->toAccountId])->keyBy('id');
                $from = $accounts->get($request->fromAccountId);
                $to = $accounts->get($request->toAccountId);
                if (! $from || ! $to || ! $from->currency || ! $to->currency) {
                    throw new InvalidTenantRequest('Both transfer accounts must be active and available to this tenant.');
                }

                $sameCurrency = (int) $from->currency_id === (int) $to->currency_id;
                if (! $sameCurrency && ($request->exchangeRate === null || $request->exchangeRate <= 0)) {
                    throw new InvalidTenantRequest('A positive exchange rate is required for cross-currency transfers.');
                }
                if ($sameCurrency && $request->exchangeRate !== null) {
                    throw new InvalidTenantRequest('Exchange rate must be omitted for same-currency transfers.');
                }

                $toAmount = $sameCurrency ? $request->fromAmount : round($request->fromAmount * $request->exchangeRate, 4);
                $createdBy = Auth::guard('tenantuser')->id();
                $transfer = $this->repository->create([
                    'tenant_id' => $tenantId,
                    'from_account_id' => $from->id,
                    'to_account_id' => $to->id,
                    'from_currency_id' => $from->currency_id,
                    'to_currency_id' => $to->currency_id,
                    'from_amount' => $request->fromAmount,
                    'to_amount' => $toAmount,
                    'exchange_rate' => $sameCurrency ? null : $request->exchangeRate,
                    'exchange_rate_source' => $sameCurrency ? null : 'manual',
                    'fee_amount' => $request->feeAmount,
                    'fee_account_id' => $request->feeAmount > 0 ? $from->id : null,
                    'note' => $request->note,
                    'transferred_at' => CarbonImmutable::now(),
                    'created_by' => $createdBy,
                ]);
                $reference = 'TRANSFER-'.$transfer->id;
                $accounting = $this->accountingService->createInternalTransfer($transfer, 'Financial account transfer', $request->fromAmount, $createdBy, $from->currency, $request->exchangeRate);
                $this->transactionService->recordAccountTransfer($from, $request->fromAmount, 'credit', $reference, FinancialAccountsTranfers::class, $request->note, $createdBy, $accounting->id);
                $this->transactionService->recordAccountTransfer($to, $toAmount, 'debit', $reference, FinancialAccountsTranfers::class, $request->note, $createdBy, $accounting->id);

                if ($request->feeAmount > 0) {
                    $feeAccounting = $this->accountingService->recordTransferFee($transfer, 'Financial account transfer fee', $request->feeAmount, $from->currency, $createdBy);
                    $this->transactionService->recordTransferFee($from, $request->feeAmount, $reference, FinancialAccountsTranfers::class, 'Transfer fee', $createdBy, $feeAccounting->id);
                }

                return $this->repository->refresh($transfer);
            });

            $resource = FinancialAccountTransferResource::fromModel($transfer);
            if ($idempotency !== null) {
                $this->idempotencyService->markCompleted($idempotency, 201, ['data' => $resource->toArray()], FinancialAccountsTranfers::class, $transfer->id);
            }

            return $resource;
        } catch (Throwable $exception) {
            if ($idempotency !== null) {
                $this->idempotencyService->markFailed($idempotency);
            }
            throw $exception;
        }
    }
}
