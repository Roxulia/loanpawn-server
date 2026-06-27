<?php

namespace App\Repository;

use App\Exceptions\RequiredValueMissing;
use App\Models\CoreModule\TenantCustomer;
use App\Models\PawnModule\PawnLoanContractSlip;
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
