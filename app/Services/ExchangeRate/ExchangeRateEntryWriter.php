<?php

namespace App\Services\ExchangeRate;

use App\Models\CoreModule\ExchangeRateEntry;
use App\Models\CoreModule\ExchangeRatePair;
use App\Repository\ExchangeRateEntryRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExchangeRateEntryWriter
{
    public function __construct(private ExchangeRateEntryRepository $entries, private ExchangeRateBusinessClock $clock) {}

    public function create(ExchangeRatePair $pair, array $data, ?int $tenantId, ?int $tenantUserId, ?int $adminId, ?CarbonImmutable $observedAt = null): ExchangeRateEntry
    {
        $scopeKey = $tenantId ? "tenant:{$tenantId}" : 'platform';
        if (! empty($data['idempotency_key'])) {
            $existing = ExchangeRateEntry::query()->where('scope_key', $scopeKey)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return $existing->load('pair.baseCurrency', 'pair.quoteCurrency');
            }
        }
        $observedAt ??= $this->clock->now($tenantId);

        return DB::transaction(function () use ($pair, $data, $tenantId, $tenantUserId, $adminId, $scopeKey, $observedAt) {
            ExchangeRatePair::query()->whereKey($pair->id)->lockForUpdate()->firstOrFail();
            return $this->entries->create(['code' => 'RATE-'.Str::upper((string) Str::ulid()), 'tenant_id' => $tenantId, 'scope_key' => $scopeKey, 'exchange_rate_pair_id' => $pair->id, 'buying_rate' => $data['buying_rate'], 'selling_rate' => $data['selling_rate'], 'effective_date' => $data['effective_date'] ?? $observedAt->toDateString(), 'observed_at' => $observedAt->utc(), 'source' => $tenantId ? 'TENANT' : 'PLATFORM', 'idempotency_key' => $data['idempotency_key'] ?? null, 'created_by_tenant_user_id' => $tenantUserId, 'created_by_platform_admin_id' => $adminId]);
        });
    }
}
