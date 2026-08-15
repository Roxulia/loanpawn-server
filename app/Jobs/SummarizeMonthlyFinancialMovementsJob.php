<?php

namespace App\Jobs;

use App\Services\TenantModule\Accounting\FinancialAccountMonthlySummaryService;
use App\Services\TenantModule\Accounting\TenantAccountingMonthlySummaryService;
use App\Support\OperationLogger;
use App\Support\RedisAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class SummarizeMonthlyFinancialMovementsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public ?string $month = null)
    {
        $this->onConnection(app(RedisAvailability::class)->selectedQueueConnection());
        $this->onQueue('scheduled');
    }

    public function handle(
        TenantAccountingMonthlySummaryService $accountingSummaries,
        FinancialAccountMonthlySummaryService $accountSummaries,
    ): void {
        app(OperationLogger::class)->run(self::class.'::handle', function () use ($accountingSummaries, $accountSummaries): void {
            $month = $this->month === null
                ? CarbonImmutable::now()->subMonthNoOverflow()->startOfMonth()
                : CarbonImmutable::parse($this->month)->startOfMonth();

            $accountingSummaries->summarizeAll($month);
            $accountSummaries->summarizeAll($month);
        });
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('summarize-monthly-financial-movements'))->expireAfter(7200)];
    }
}
