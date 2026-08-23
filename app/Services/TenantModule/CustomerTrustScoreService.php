<?php

namespace App\Services\TenantModule;

use App\Models\CoreModule\TenantCustomer;
use App\Repository\CustomerTrustScoreRepository;
use App\Services\BaseTenantService;
use App\Support\TenantScopedCacheKeys;
use Carbon\CarbonImmutable;
use Throwable;

class CustomerTrustScoreService extends BaseTenantService
{
    public function __construct(
        private CustomerTrustScoreRepository $repository,
        private TenantScopedCacheKeys $tenantScopedCacheKeys,
    ) {
    }

    public function recalculateForCustomer(int $customerId): int
    {
        $tenantId = $this->resolveCurrentTenantId();

        return $this->recalculateForTenantCustomer($tenantId, $customerId);
    }

    public function recalculateForTenantCustomer(int $tenantId, int $customerId): int
    {
        $score = $this->calculateForTenantCustomer($tenantId, $customerId);

        $this->repository->updateTrustScore($tenantId, $customerId, $score);
        $this->tenantScopedCacheKeys->bumpVersion('tenant-customer-list', tenantId: $tenantId);

        return $score;
    }

    /**
     * @param array<int, int> $customerIds
     */
    public function recalculateForCustomers(array $customerIds): void
    {
        foreach (array_values(array_unique(array_filter($customerIds))) as $customerId) {
            $this->recalculateForCustomer((int) $customerId);
        }
    }

    public function calculateForCustomer(int $customerId): int
    {
        return $this->calculateForTenantCustomer($this->resolveCurrentTenantId(), $customerId);
    }

    public function calculateForTenantCustomer(int $tenantId, int $customerId): int
    {
        $metrics = $this->repository->metricsForCustomer(
            $tenantId,
            $customerId,
            CarbonImmutable::now()->startOfDay()
        );

        if ($metrics['slip_count'] === 0) {
            return TenantCustomer::DEFAULT_TRUST_SCORE;
        }

        $historyScore = $this->paymentHistoryScore($metrics)
            + $this->outstandingBurdenScore($metrics)
            + $this->relationshipLengthScore($metrics)
            + $this->activityScore($metrics)
            + $this->debtCleanlinessScore($metrics);
        $historyAdjustment = $historyScore - TenantCustomer::DEFAULT_TRUST_SCORE;
        $score = TenantCustomer::DEFAULT_TRUST_SCORE + $historyAdjustment;

        return max(0, min(TenantCustomer::MAX_TRUST_SCORE, (int) round($score)));
    }

    /**
     * @return array{processed: int, changed: int, unchanged: int, failed: int}
     */
    public function backfillAll(bool $apply): array
    {
        $summary = [
            'processed' => 0,
            'changed' => 0,
            'unchanged' => 0,
            'failed' => 0,
        ];
        $affectedTenantIds = [];

        foreach ($this->repository->activeCustomersForBackfill() as $customer) {
            $summary['processed']++;

            try {
                $score = $this->calculateForTenantCustomer(
                    (int) $customer->tenant_id,
                    (int) $customer->id,
                );

                if ($score === (int) $customer->trust_score) {
                    $summary['unchanged']++;

                    continue;
                }

                $summary['changed']++;

                if ($apply) {
                    $tenantId = (int) $customer->tenant_id;
                    $this->repository->updateTrustScore($tenantId, (int) $customer->id, $score);
                    $affectedTenantIds[$tenantId] = true;
                }
            } catch (Throwable) {
                $summary['failed']++;
            }
        }

        foreach (array_keys($affectedTenantIds) as $tenantId) {
            $this->tenantScopedCacheKeys->bumpVersion('tenant-customer-list', tenantId: $tenantId);
        }

        return $summary;
    }

    protected function paymentHistoryScore(array $metrics): float
    {
        $dueInterestCount = max(1, $metrics['due_interest_count']);
        $onTimeRatio = $metrics['on_time_interest_count'] / $dueInterestCount;
        $paidRatio = $metrics['paid_interest_count'] / $dueInterestCount;

        $score = (75 * $onTimeRatio) + (25 * $paidRatio);
        $score += min(15, $metrics['redeemed_slip_count'] * 3);
        $score -= min(45, ($metrics['unpaid_due_interest_count'] * 8) + ($metrics['expired_slip_count'] * 15));

        return max(0, min(115, $score));
    }

    protected function outstandingBurdenScore(array $metrics): float
    {
        if ($metrics['lifetime_principal'] <= 0.0) {
            return 0.0;
        }

        $utilization = min(1.0, $metrics['active_principal'] / $metrics['lifetime_principal']);

        return max(0, 64 * (1 - $utilization));
    }

    protected function relationshipLengthScore(array $metrics): float
    {
        if ($metrics['first_slip_date'] === null) {
            return 0.0;
        }

        $firstSlipDate = CarbonImmutable::parse($metrics['first_slip_date'])->startOfDay();
        $days = max(0, $firstSlipDate->diffInDays(CarbonImmutable::now()->startOfDay()));

        return min(38, ($days / 365) * 38);
    }

    protected function activityScore(array $metrics): float
    {
        return min(25, $metrics['redeemed_slip_count'] * 2.5);
    }

    protected function debtCleanlinessScore(array $metrics): float
    {
        if ($metrics['debt_count'] === 0) {
            return 13;
        }

        $unpaidDebtRatio = $metrics['unpaid_debt_count'] / max(1, $metrics['debt_count']);
        $principalRatio = $metrics['lifetime_principal'] <= 0.0
            ? 1.0
            : min(1.0, $metrics['unpaid_debt_amount'] / $metrics['lifetime_principal']);

        return max(0, 13 * (1 - (($unpaidDebtRatio + $principalRatio) / 2)));
    }
}
