<?php

namespace App\Services\ExchangeRate;

use App\Models\CoreModule\ExchangeRateEntry;
use App\Repository\ExchangeRateEntryRepository;
use App\Exceptions\InvalidTenantRequest;
use App\Utility\MessageCode;
use App\Utility\Messages;

class ExchangeRateActionPolicy
{
    public function __construct(private ExchangeRateEntryRepository $entries, private ExchangeRateBusinessClock $clock, private Messages $messages) {}

    public function apply(ExchangeRateEntry $entry): ExchangeRateEntry
    {
        $canCorrect = false;
        $canVoid = false;
        if (! $entry->is_void) {
            $today = $this->clock->now($entry->tenant_id)->toDateString();
            $entryDate = $entry->effective_date->toDateString();
            if ($entryDate === $today) {
                $canCorrect = true;
                $canVoid = true;
            } elseif ($entryDate === $this->clock->now($entry->tenant_id)->subDay()->toDateString()) {
                $latest = $this->entries->latestActiveForDay($entry->scope_key, $entry->exchange_rate_pair_id, $entryDate);
                $canCorrect = $latest?->id === $entry->id;
            }
        }
        $entry->setAttribute('can_correct', $canCorrect);
        $entry->setAttribute('can_void', $canVoid);

        return $entry;
    }

    public function assertCorrectable(ExchangeRateEntry $entry): void
    {
        if (! $this->apply($entry)->getAttribute('can_correct')) throw new InvalidTenantRequest($this->messages->responseMessage(MessageCode::FinanceExchangeRateActionWindowClosed));
    }

    public function assertVoidable(ExchangeRateEntry $entry): void
    {
        if (! $this->apply($entry)->getAttribute('can_void')) throw new InvalidTenantRequest($this->messages->responseMessage(MessageCode::FinanceExchangeRateActionWindowClosed));
    }
}
