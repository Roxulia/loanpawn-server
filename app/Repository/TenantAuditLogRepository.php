<?php

namespace App\Repository;

use App\Models\CoreModule\TenantAuditLog;
use App\Exceptions\RequiredValueMissing;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

class TenantAuditLogRepository
{
    public function create(array $data): TenantAuditLog
    {
        $this->requireValue($data, 'tenant_code');

        return TenantAuditLog::query()->create($data);
    }

    protected function requireValue(array $data, string $key): void
    {
        if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            throw new RequiredValueMissing("Tenant audit log {$key} is required.");
        }
    }

    public function getBetweenDates(CarbonInterface $startDate, CarbonInterface $endDate): Collection
    {
        return TenantAuditLog::query()
            ->with(['actorUser', 'actorAdmin'])
            ->whereBetween('created_at', [$startDate, $endDate])
            ->orderByDesc('created_at')
            ->get();
    }
}
