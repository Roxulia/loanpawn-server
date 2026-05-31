<?php

namespace App\Repository;

use App\Models\CoreModule\TenantCustomer;
use App\Exceptions\RequiredValueMissing;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class TenantCustomerRepository
{
    public function paginate(int $perPage = 15, ?string $search = null): LengthAwarePaginator
    {
        $query = TenantCustomer::query()
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

    public function findDuplicateForCreate(int $tenantId, ?string $email, ?string $phone): ?TenantCustomer
    {
        if ($email === null && $phone === null) {
            return null;
        }

        return TenantCustomer::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where(function ($query) use ($email, $phone) {
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
