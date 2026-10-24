<?php

namespace App\Jobs;

use App\Repository\TenantSettingRepository;
use App\Services\TenantModule\OwnershipMonthlySummaryService;
use App\Support\{OperationLogger, RedisAvailability};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/** Serializable month/tenant work; dependencies are explicit and never stored in queue payloads. */
class SummarizeMonthlyOwnershipMovementsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public ?string $month = null, public ?int $tenantId = null,
        ?string $queueConnection = null, RedisAvailability $redisAvailability = new RedisAvailability())
    {
        // A child reuses its root's selected connection, avoiding a Redis probe for every tenant.
        $this->onConnection($queueConnection ?? $redisAvailability->selectedQueueConnection());
        $this->onQueue('scheduled');
    }

    public function handle(OwnershipMonthlySummaryService $summaries, TenantSettingRepository $tenantRepository,
        OperationLogger $operationLogger = new OperationLogger()): void
    {
        // Use the established success/failure logger without resolving services inside orchestration.
        $operationLogger->run(self::class.'::handle', function () use ($summaries, $tenantRepository): void {
            if ($this->tenantId !== null) {
                $summaries->summarizeTenant($this->tenantId, $this->month);
                return;
            }
            $this->dispatchPinnedTenantMonths($summaries, $tenantRepository);
        });
    }

    public function middleware(): array
    {
        // Tenant overlap protection complements, but does not replace, the database aggregate anchor.
        return [(new WithoutOverlapping('summarize-monthly-ownership-movements:'.($this->tenantId ?? 'all')))
            ->releaseAfter(60)->expireAfter(7200)];
    }

    private function dispatchPinnedTenantMonths(OwnershipMonthlySummaryService $summaries, TenantSettingRepository $tenantRepository): void
    {
        // Keyset registry batches keep fan-out memory bounded; each serialized child pins its period.
        foreach ($tenantRepository->streamTenantIds() as $tenantId) {
            $month = $summaries->completedMonthForTenant($tenantId, $this->month);
            self::dispatch($month, $tenantId, $this->connection);
        }
    }
}
