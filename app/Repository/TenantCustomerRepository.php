<?php

namespace App\Repository;

use App\Models\CoreModule\TenantCustomer;
use App\Models\PawnModule\PawnLoanContractSlip;
use App\Exceptions\RequiredValueMissing;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class TenantCustomerRepository
{
    public function paginate(int $perPage = 15, ?string $search = null): LengthAwarePaginator
    {
        $query = TenantCustomer::query()
            ->withCount([
                'pawnSlips as active_slip_count' => fn ($query) => $query
                    ->where('is_deleted', false)
                    ->whereRaw('LOWER(status) = ?', ['active']),
            ])
            ->where('is_deleted', false)
            ->orderByDesc('id');

        if ($search !== null) {
            $query->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%");
            });
        }

        return $query->paginate($perPage);
    }

    public function customerListSummary(CarbonInterface $today, int $riskTrustScoreThreshold): array
    {
        $customerQuery = TenantCustomer::query()
            ->where('is_deleted', false);

        return [
            'totalClients' => (clone $customerQuery)->count(),
            'averageTrustScore' => (float) (clone $customerQuery)->avg('trust_score'),
            'activePawnLoans' => PawnLoanContractSlip::query()
                ->where('is_deleted', false)
                ->whereRaw('LOWER(status) = ?', ['active'])
                ->count(),
            'riskFlagged' => (clone $customerQuery)
                ->where(function ($query) use ($today, $riskTrustScoreThreshold) {
                    $query->where('trust_score', '<', $riskTrustScoreThreshold)
                        ->orWhereHas('pawnSlips', function ($slipQuery) use ($today) {
                            $slipQuery->where('is_deleted', false)
                                ->where(function ($statusQuery) use ($today) {
                                    $statusQuery->whereRaw('LOWER(status) = ?', ['expired'])
                                        ->orWhere(function ($activeQuery) use ($today) {
                                            $activeQuery->whereRaw('LOWER(status) = ?', ['active'])
                                                ->whereDate('expire_date', '<', $today->toDateString());
                                        });
                                });
                        });
                })
                ->count(),
        ];
    }

    /**
     * @param array<int, int> $customerIds
     * @return Collection<int, PawnLoanContractSlip>
     */
    public function latestSlipsForCustomerIds(array $customerIds): Collection
    {
        if ($customerIds === []) {
            return collect();
        }

        return PawnLoanContractSlip::query()
            ->where('is_deleted', false)
            ->whereIn('customer_id', $customerIds)
            ->orderByDesc('created_date')
            ->orderByDesc('id')
            ->get()
            ->unique('customer_id')
            ->keyBy('customer_id');
    }

    public function create(array $data): TenantCustomer
    {
        $this->requireValue($data, 'code');

        return TenantCustomer::query()->create($data);
    }

    protected function requireValue(array $data, string $key): void
    {
        if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            throw new RequiredValueMissing("Tenant customer {$key} is required.");
        }
    }

    public function update(TenantCustomer $customer, array $data): TenantCustomer
    {
        $customer->update($data);

        return $customer->refresh();
    }

    public function updateWithLock(TenantCustomer $customer, array $data): TenantCustomer
    {
        $lockedCustomer = TenantCustomer::query()
            ->whereKey($customer->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        return $this->update($lockedCustomer, $data);
    }

    public function findById(int $customerId): ?TenantCustomer
    {
        return TenantCustomer::query()
            ->where('id', $customerId)
            ->where('is_deleted', false)
            ->first();
    }

    public function findByCode(string $code): ?TenantCustomer
    {
        return TenantCustomer::query()
            ->where('code', $code)
            ->where('is_deleted', false)
            ->first();
    }

    public function customerSlipMetrics(int $customerId): array
    {
        $tenantId = app(TenantContext::class)->id();
        $slips = PawnLoanContractSlip::query()
            ->where('customer_id', $customerId)
            ->where('is_deleted', false)
            ->get(['id', 'loan_amount', 'created_date', 'expire_date', 'status']);

        $totalSlips = $slips->count();
        $activeSlips = $slips->filter(fn (PawnLoanContractSlip $slip): bool => strtolower((string) $slip->status) === 'active')->count();
        $completedSlips = $slips->filter(fn (PawnLoanContractSlip $slip): bool => strtolower((string) $slip->status) === 'redeemed')->count();
        $activeLoanAmount = $slips
            ->filter(fn (PawnLoanContractSlip $slip): bool => strtolower((string) $slip->status) === 'active')
            ->sum(fn (PawnLoanContractSlip $slip): float => (float) $slip->loan_amount);
        $totalLoanAmount = $slips->sum(fn (PawnLoanContractSlip $slip): float => (float) $slip->loan_amount);
        $termDays = $slips
            ->map(fn (PawnLoanContractSlip $slip): int => (int) round(CarbonImmutable::parse($slip->created_date)->diffInDays(CarbonImmutable::parse($slip->expire_date))))
            ->filter(fn (int $days): bool => $days >= 0);
        $latestActivityDate = $slips->max(fn (PawnLoanContractSlip $slip): ?string => $slip->created_date?->toDateString());
        $firstSlipDate = $slips->min(fn (PawnLoanContractSlip $slip): ?string => $slip->created_date?->toDateString());
        $totalInterestPaid = (float) DB::table('pawn_interest_payments')
            ->join('pawn_loan_contract_slips', 'pawn_loan_contract_slips.id', '=', 'pawn_interest_payments.slip_id')
            ->where('pawn_interest_payments.tenant_id', $tenantId)
            ->where('pawn_loan_contract_slips.tenant_id', $tenantId)
            ->where('pawn_loan_contract_slips.customer_id', $customerId)
            ->where('pawn_loan_contract_slips.is_deleted', false)
            ->where('pawn_interest_payments.is_deleted', false)
            ->where('pawn_interest_payments.is_paid', true)
            ->sum('pawn_interest_payments.payment_amount');

        return [
            'total_slips' => $totalSlips,
            'active_slips' => $activeSlips,
            'completed_slips' => $completedSlips,
            'total_loan_amount' => $totalLoanAmount,
            'active_loan_amount' => $activeLoanAmount,
            'total_interest_paid' => $totalInterestPaid,
            'average_loan_term_days' => $termDays->count() > 0 ? (int) round($termDays->average()) : 0,
            'redemption_rate' => $totalSlips > 0 ? (int) round(($completedSlips / $totalSlips) * 100) : 0,
            'latest_activity_date' => $latestActivityDate,
            'first_slip_date' => $firstSlipDate,
        ];
    }

    public function activeSlipsForCustomer(int $customerId, int $limit = 8): array
    {
        return PawnLoanContractSlip::query()
            ->with(['interestType', 'slipItems.materialType'])
            ->where('customer_id', $customerId)
            ->where('is_deleted', false)
            ->whereRaw('LOWER(status) = ?', ['active'])
            ->orderBy('expire_date')
            ->limit($limit)
            ->get()
            ->map(function (PawnLoanContractSlip $slip): array {
                $firstItem = $slip->slipItems->first();

                return [
                    'id' => $slip->id,
                    'slip_no' => $slip->slip_no,
                    'pawned_item' => $firstItem?->name ?? $firstItem?->type ?? '-',
                    'loan_amount' => (float) $slip->loan_amount,
                    'interest_rate' => (float) $slip->interest_rate,
                    'interest_type_name' => $slip->interestType?->name,
                    'expire_date' => $slip->expire_date?->toDateString(),
                    'status' => $slip->status,
                ];
            })
            ->all();
    }

    public function findByIdWithLock(int $customerId): ?TenantCustomer
    {
        return TenantCustomer::query()
            ->where('id', $customerId)
            ->where('is_deleted', false)
            ->lockForUpdate()
            ->first();
    }

    public function findByCodeWithLock(string $code): ?TenantCustomer
    {
        return TenantCustomer::query()
            ->where('code', $code)
            ->where('is_deleted', false)
            ->lockForUpdate()
            ->first();
    }

    public function findDuplicateForCreate(int $tenantId, ?string $email, ?string $phone,?string $nrc): ?TenantCustomer
    {
        if ($email === null && $phone === null && $nrc === null) {
            return null;
        }

        return TenantCustomer::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where(function ($query) use ($email, $phone,$nrc) {
                if ($nrc !== null){
                    $query->orWhere('nrc', $nrc);
                }
                if ($phone !== null) {
                    $query->orWhere('phone', $phone);
                }

                if ($email !== null) {
                    $query->orWhere('email', $email);
                }
            })
            ->orderByDesc('id')
            ->first();
    }

    public function existsByField(string $field, string $value, ?int $ignoreCustomerId = null): bool
    {
        $query = TenantCustomer::withTrashed()
            ->where($field, $value);

        if ($ignoreCustomerId !== null) {
            $query->where('id', '!=', $ignoreCustomerId);
        }

        return $query->exists();
    }
}
