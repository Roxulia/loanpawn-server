<?php

namespace App\Repository;

use App\Exceptions\RequiredValueMissing;
use App\Models\CoreModule\TenantCapital;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class TenantCapitalRepository
{
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return TenantCapital::query()
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function create(array $data): TenantCapital
    {
        $this->requireValue($data, 'code');

        return TenantCapital::query()->create($data);
    }

    public function update(TenantCapital $capital, array $data): TenantCapital
    {
        $capital->update($data);

        return $capital->refresh();
    }

    public function updateWithLock(TenantCapital $capital, array $data): TenantCapital
    {
        $lockedCapital = TenantCapital::query()
            ->whereKey($capital->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        return $this->update($lockedCapital, $data);
    }

    public function delete(TenantCapital $capital): void
    {
        $capital->delete();
    }

    public function findById(int $capitalId): ?TenantCapital
    {
        return TenantCapital::query()->find($capitalId);
    }

    public function findByCode(string $code): ?TenantCapital
    {
        return TenantCapital::query()
            ->where('code', $code)
            ->first();
    }

    public function findByIdWithLock(int $capitalId): ?TenantCapital
    {
        return TenantCapital::query()
            ->whereKey($capitalId)
            ->lockForUpdate()
            ->first();
    }

    protected function requireValue(array $data, string $key): void
    {
        if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            throw new RequiredValueMissing("Tenant capital {$key} is required.");
        }
    }
}
