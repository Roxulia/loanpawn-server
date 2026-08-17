<?php

namespace App\Services\ExchangeRate;

use App\Models\CoreModule\DailyExchangeRateSummary;
use App\Repository\DailyExchangeRateSummaryRepository;

class DailyExchangeRateSummaryPersistenceService
{
    public function __construct(private DailyExchangeRateSummaryRepository $summaries) {}

    public function upsert(array $identity, array $values): DailyExchangeRateSummary
    {
        return $this->summaries->upsert($identity, $values);
    }

    public function delete(array $identity): void
    {
        $this->summaries->delete($identity);
    }

}
