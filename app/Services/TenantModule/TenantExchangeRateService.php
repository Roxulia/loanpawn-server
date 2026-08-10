<?php

namespace App\Services\TenantModule;

use App\Exceptions\InvalidTenantRequest;
use App\Exceptions\TenantAccessDenied;
use App\Models\CoreModule\ExchangeRateEntry;
use App\Repository\ExchangeRateEntryRepository;
use App\Repository\ExchangeRatePairRepository;
use App\Services\BaseTenantService;
use App\Services\ExchangeRate\ExchangeRateCorrectionService;
use App\Services\ExchangeRate\ExchangeRateEntryWriter;
use App\Services\ExchangeRate\ExchangeRateResolverService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

class TenantExchangeRateService extends BaseTenantService
{
    public function __construct(private ExchangeRateEntryRepository $entries, private ExchangeRatePairRepository $pairs, private ExchangeRateEntryWriter $writer, private ExchangeRateCorrectionService $corrections, private ExchangeRateResolverService $resolver) {}

    public function list(int $perPage = 50): LengthAwarePaginator
    {
        return $this->entries->visibleToTenant($this->resolveCurrentTenantId(), $perPage);
    }

    public function show(string $code): ExchangeRateEntry
    {
        return $this->entries->findVisible($code, $this->resolveCurrentTenantId()) ?? throw new InvalidTenantRequest('Exchange rate not found.');
    }

    public function create(array $data): ExchangeRateEntry
    {
        $tenantId = $this->resolveCurrentTenantId();
        $pair = $this->pairs->findVisible($data['pair_code'], $tenantId);
        if (! $pair || ! $pair->is_active) {
            throw new InvalidTenantRequest('Choose an active visible exchange pair.');
        }

        return $this->writer->create($pair, $data, $tenantId, Auth::guard('tenantuser')->id(), null);
    }

    public function correct(string $code, string $rate, string $reason): ExchangeRateEntry
    {
        $entry = $this->owned($code);

        return $this->corrections->correct($entry, $rate, $reason, Auth::guard('tenantuser')->id(), null);
    }

    public function void(string $code, string $reason): void
    {
        $this->corrections->void($this->owned($code), $reason, Auth::guard('tenantuser')->id(), null);
    }

    public function resolve(string $pairCode, string $date): ?ExchangeRateEntry
    {
        $tenantId = $this->resolveCurrentTenantId();
        $pair = $this->pairs->findVisible($pairCode, $tenantId) ?? throw new InvalidTenantRequest('Exchange pair not found.');

        return $this->resolver->resolve($pair, $tenantId, $date);
    }

    private function owned(string $code): ExchangeRateEntry
    {
        return $this->entries->findOwned($code, $this->resolveCurrentTenantId()) ?? throw new TenantAccessDenied('Only tenant-created rate entries can be changed.');
    }
}
