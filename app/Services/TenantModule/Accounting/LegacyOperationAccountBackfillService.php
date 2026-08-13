<?php

namespace App\Services\TenantModule\Accounting;

use App\Repository\Accounting\LegacyOperationAccountBackfillRepository;
use Illuminate\Support\Facades\DB;
use Throwable;

class LegacyOperationAccountBackfillService
{
    private const FIELDS = [
        'loan_contract_account',
        'interest_created_account',
        'interest_accept_account',
        'redemption_account',
        'debt_created_account',
        'debt_accept_account',
    ];

    public function __construct(
        private LegacyOperationAccountBackfillRepository $repository,
        private MultiAccountManagement $multiAccountManagement,
    ) {}

    public function backfill(bool $apply): array
    {
        $summary = [
            'tenants_checked' => 0,
            'accounts_created' => 0,
            'accounts_promoted' => 0,
            'fields' => array_fill_keys(self::FIELDS, 0),
            'failures' => [],
        ];

        foreach ($this->repository->tenantIds() as $tenantIdValue) {
            $tenantId = (int) $tenantIdValue;
            $summary['tenants_checked']++;
            $account = $this->repository->activeDefaultAccount($tenantId);
            $accountMetric = $account === null
                ? ($this->repository->hasFinancialAccount($tenantId) ? 'accounts_promoted' : 'accounts_created')
                : null;

            try {
                $counts = $this->repository->missingCounts($tenantId);
                if ($apply) {
                    $counts = DB::transaction(function () use ($tenantId, $account, $counts): array {
                        $resolvedAccount = $account ?? $this->multiAccountManagement->createDefaultForTenant($tenantId);
                        if (array_sum($counts) === 0) {
                            return $counts;
                        }

                        return $this->repository->backfill($tenantId, (int) $resolvedAccount->id);
                    });
                }
                if ($accountMetric !== null) {
                    $summary[$accountMetric]++;
                }

                foreach (self::FIELDS as $field) {
                    $summary['fields'][$field] += $counts[$field];
                }
            } catch (Throwable $exception) {
                $summary['failures'][] = ['tenant_id' => $tenantId, 'reason' => $exception->getMessage()];
            }
        }

        $summary['total'] = array_sum($summary['fields']);

        return $summary;
    }
}
