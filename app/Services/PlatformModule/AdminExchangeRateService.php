<?php

namespace App\Services\PlatformModule;

use App\Exceptions\InvalidTenantRequest;
use App\Models\CoreModule\ExchangeRateEntry;
use App\Repository\ExchangeRateEntryRepository;
use App\Repository\ExchangeRatePairRepository;
use App\Services\ExchangeRate\ExchangeRateCorrectionService;
use App\Services\ExchangeRate\ExchangeRateEntryWriter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

class AdminExchangeRateService
{
    public function __construct(private ExchangeRateEntryRepository $entries, private ExchangeRatePairRepository $pairs, private ExchangeRateEntryWriter $writer, private ExchangeRateCorrectionService $corrections) {}

    public function list(int $perPage = 50): LengthAwarePaginator
    {
        return $this->entries->platform($perPage);
    }

    public function create(array $data): ExchangeRateEntry
    {
        $pair = $this->pairs->findOwned($data['pair_code'], null);
        if (! $pair || ! $pair->is_active) {
            throw new InvalidTenantRequest('Choose an active default exchange pair.');
        }

        return $this->writer->create($pair, $data, null, null, Auth::guard('platformadmin')->id());
    }

    public function correct(ExchangeRateEntry $entry, string $rate, string $reason): ExchangeRateEntry
    {
        $this->assertPlatform($entry);

        return $this->corrections->correct($entry, $rate, $reason, null, Auth::guard('platformadmin')->id());
    }

    public function void(ExchangeRateEntry $entry, string $reason): void
    {
        $this->assertPlatform($entry);
        $this->corrections->void($entry, $reason, null, Auth::guard('platformadmin')->id());
    }

    private function assertPlatform(ExchangeRateEntry $entry): void
    {
        if ($entry->tenant_id !== null) {
            throw new InvalidTenantRequest('Only platform rates are managed here.');
        }
    }
}
