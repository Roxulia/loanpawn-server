<?php

namespace App\Jobs;

use App\Repository\TenantSettingRepository;
use App\Services\TenantModule\InventoryMonthlySummaryService;
use App\Support\OperationLogger;
use App\Support\RedisAvailability;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Dispatches monthly Inventory checkpoints using the scheduled queue conventions.
 * The root job fans out by tenant; tenant jobs establish scope through the summary
 * service and safely resume completed items after retries or duplicate dispatches.
 */
class SummarizeMonthlyInventoryMovementsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ?string $month = null,
        public ?int $tenantId = null,
    ) {
        // Match the existing financial summarizer's queue connection and scheduled queue.
        $this->onConnection(app(RedisAvailability::class)->selectedQueueConnection());
        $this->onQueue('scheduled');
    }

    public function handle(
        InventoryMonthlySummaryService $summaries,
        TenantSettingRepository $tenantRepository,
    ): void {
        // Reuse operation logging for both tenant dispatch and checkpoint publication.
        app(OperationLogger::class)->run(self::class.'::handle', function () use ($summaries, $tenantRepository): void {
            if ($this->tenantId === null) {
                // Reuse tenant discovery and separate queue work so one tenant can retry independently.
                foreach ($tenantRepository->allTenantIds() as $tenantId) {
                    $month = $summaries->completedMonthForTenant((int) $tenantId, $this->month);
                    self::dispatch($month, (int) $tenantId);
                }

                return;
            }

            // A null month is resolved in the tenant's timezone, not the queue worker's timezone.
            $summaries->summarizeTenant($this->tenantId, $this->month);
        });
    }

    public function middleware(): array
    {
        // Avoid overlapping jobs for one tenant; database item locks remain authoritative.
        return [
            (new WithoutOverlapping('summarize-monthly-inventory-movements:'.($this->tenantId ?? 'all')))
                ->releaseAfter(60)
                ->expireAfter(7200)
            ];
    }
}
