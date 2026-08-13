<?php

namespace App\Services\PlatformModule;

use App\DataObjects\RequestObjects\CorrectExchangeRateRequest;
use App\DataObjects\RequestObjects\StoreExchangeRateRequest;
use App\DataObjects\RequestObjects\VoidExchangeRateRequest;
use App\Exceptions\InvalidTenantRequest;
use App\Models\CoreModule\ExchangeRateEntry;
use App\Repository\ExchangeRateEntryRepository;
use App\Repository\ExchangeRatePairRepository;
use App\Services\ExchangeRate\ExchangeRateCorrectionService;
use App\Services\ExchangeRate\ExchangeRateActionPolicy;
use App\Services\ExchangeRate\ExchangeRateEntryWriter;
use App\Utility\MessageCode;
use App\Utility\Messages;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

class AdminExchangeRateService
{
    public function __construct(private ExchangeRateEntryRepository $entries, private ExchangeRatePairRepository $pairs, private ExchangeRateEntryWriter $writer, private ExchangeRateCorrectionService $corrections, private Messages $messages, private ExchangeRateActionPolicy $actions) {}

    public function list(int $perPage = 50): LengthAwarePaginator
    {
        $page = $this->entries->platform($perPage);
        $page->through(fn (ExchangeRateEntry $entry) => $this->actions->apply($entry));

        return $page;
    }

    public function create(StoreExchangeRateRequest $request): ExchangeRateEntry
    {
        $pair = $this->pairs->findOwned($request->pairCode, null);
        if (! $pair || ! $pair->is_active) {
            throw new InvalidTenantRequest($this->messages->responseMessage(MessageCode::FinanceActiveDefaultExchangePairRequired));
        }

        return $this->writer->create($pair, $request->toArray(), null, null, Auth::guard('platformadmin')->id());
    }

    public function correct(ExchangeRateEntry $entry, CorrectExchangeRateRequest $request): ExchangeRateEntry
    {
        $this->assertPlatform($entry);
        $this->actions->assertCorrectable($entry);

        return $this->corrections->correct($entry, $request->buyingRate, $request->sellingRate, $request->reason, null, Auth::guard('platformadmin')->id());
    }

    public function void(ExchangeRateEntry $entry, VoidExchangeRateRequest $request): void
    {
        $this->assertPlatform($entry);
        $this->actions->assertVoidable($entry);
        $this->corrections->void($entry, $request->reason, null, Auth::guard('platformadmin')->id());
    }

    private function assertPlatform(ExchangeRateEntry $entry): void
    {
        if ($entry->tenant_id !== null) {
            throw new InvalidTenantRequest($this->messages->responseMessage(MessageCode::FinancePlatformExchangeRateRequired));
        }
    }
}
