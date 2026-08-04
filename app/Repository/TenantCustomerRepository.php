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
                                                ->whereDate('expire_at', '<', $today->toDateString());
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
            ->orderByDesc('created_at')
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
            ->get(['id', 'loan_amount', 'created_at', 'expire_at', 'status']);

        $totalSlips = $slips->count();
        $activeSlips = $slips->filter(fn (PawnLoanContractSlip $slip): bool => strtolower((string) $slip->status) === 'active')->count();
        $completedSlips = $slips->filter(fn (PawnLoanContractSlip $slip): bool => strtolower((string) $slip->status) === 'redeemed')->count();
        $activeLoanAmount = $slips
            ->filter(fn (PawnLoanContractSlip $slip): bool => strtolower((string) $slip->status) === 'active')
            ->sum(fn (PawnLoanContractSlip $slip): float => (float) $slip->loan_amount);
        $totalLoanAmount = $slips->sum(fn (PawnLoanContractSlip $slip): float => (float) $slip->loan_amount);
        $termDays = $slips
            ->map(fn (PawnLoanContractSlip $slip): int => (int) round(CarbonImmutable::parse($slip->created_at)->diffInDays(CarbonImmutable::parse($slip->expire_at))))
            ->filter(fn (int $days): bool => $days >= 0);
        $latestActivityDate = $slips->max(fn (PawnLoanContractSlip $slip): ?string => $slip->created_at?->toDateString());
        $firstSlipDate = $slips->min(fn (PawnLoanContractSlip $slip): ?string => $slip->created_at?->toDateString());
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
            ->with(['interestType', 'slipItems.materialType', 'slipItems.itemCategoryType'])
            ->where('customer_id', $customerId)
            ->where('is_deleted', false)
            ->whereRaw('LOWER(status) = ?', ['active'])
            ->orderBy('expire_at')
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
                    'expire_at' => $slip->expire_at?->toISOString(),
                    'status' => $slip->status,
                ];
            })
            ->all();
    }

    public function unpaidDebtsForCustomer(int $customerId, int $limit = 8): array
    {
        $tenantId = app(TenantContext::class)->id();

        return DB::table('tenant_debts')
            ->leftJoin('pawn_loan_contract_slips', 'pawn_loan_contract_slips.id', '=', 'tenant_debts.slip_id')
            ->where('tenant_debts.tenant_id', $tenantId)
            ->where('tenant_debts.is_deleted', false)
            ->where('tenant_debts.is_paid', false)
            ->where(function ($query) use ($tenantId, $customerId) {
                $query->where('tenant_debts.customer_id', $customerId)
                    ->orWhere(function ($slipQuery) use ($tenantId, $customerId) {
                        $slipQuery->where('pawn_loan_contract_slips.tenant_id', $tenantId)
                            ->where('pawn_loan_contract_slips.customer_id', $customerId)
                            ->where('pawn_loan_contract_slips.is_deleted', false)
                            ->whereNull('pawn_loan_contract_slips.deleted_at');
                    });
            })
            ->orderByDesc('tenant_debts.created_at')
            ->orderByDesc('tenant_debts.id')
            ->limit($limit)
            ->get([
                'tenant_debts.id',
                'tenant_debts.code',
                'tenant_debts.amount',
                'tenant_debts.tag',
                'tenant_debts.created_at',
            ])
            ->map(fn ($debt): array => [
                'id' => (int) $debt->id,
                'code' => $debt->code,
                'amount' => (float) $debt->amount,
                'tag' => $debt->tag,
                'created_at' => $debt->created_at === null ? null : CarbonImmutable::parse($debt->created_at)->toISOString(),
            ])
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

    public function findDuplicateForCreate(int $tenantId, ?string $email, ?string $phone, ?string $nrc): ?TenantCustomer
    {
        if ($email === null && $phone === null && $nrc === null) {
            return null;
        }

        return TenantCustomer::query()
            ->where('tenant_id', $tenantId)
            ->where('is_deleted', false)
            ->whereNull('deleted_at')
            ->where(function ($query) use ($email, $phone, $nrc) {
                if ($nrc !== null) {
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
        $query = TenantCustomer::query()
            ->where('tenant_id', app(TenantContext::class)->id())
            ->where('is_deleted', false)
            ->whereNull('deleted_at')
            ->where($field, $value);

        if ($ignoreCustomerId !== null) {
            $query->where('id', '!=', $ignoreCustomerId);
        }

        return $query->exists();
    }
}
