<?php

namespace App\Console\Commands;

use App\Services\PawnModule\InterestScheduleRepairService;
use Illuminate\Console\Command;

class RepairInterestSchedules extends Command
{
    protected $signature = 'pawn:repair-interest-schedules
        {--dry-run : Analyze and report without writing (default)}
        {--apply : Delete future unpaid rows and restore due rows}
        {--tenant= : Limit processing to one tenant ID}
        {--slip= : Limit processing to one slip number}';

    protected $description = 'Repair active pawn-slip interest schedules using incremental accrual rules';

    public function handle(InterestScheduleRepairService $service): int
    {
        if ($this->option('dry-run') && $this->option('apply')) {
            $this->error('Choose either --dry-run or --apply, not both.');

            return self::FAILURE;
        }

        $tenantId = $this->option('tenant');
        if ($tenantId !== null && (! ctype_digit((string) $tenantId) || (int) $tenantId < 1)) {
            $this->error('--tenant must be a positive integer.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $slipNo = trim((string) ($this->option('slip') ?? ''));
        $this->info($apply ? 'APPLY mode' : 'DRY-RUN mode (no writes)');
        $summary = $service->repair(
            $apply,
            $tenantId === null ? null : (int) $tenantId,
            $slipNo === '' ? null : $slipNo,
        );

        $this->table(
            ['Scanned active', $apply ? 'Repaired' : 'Would clean future rows', 'Already correct', 'Skipped status', 'Skipped no payment', 'Failed'],
            [[
                $summary->scanned,
                $summary->repaired,
                $summary->alreadyCorrect,
                $summary->skippedByStatus,
                $summary->skippedWithoutPayment,
                count($summary->failures),
            ]],
        );

        if ($summary->failures !== []) {
            $this->warn('Failed slips:');
            $this->table(['Tenant ID', 'Slip ID', 'Slip No.', 'Reason'], array_map(
                fn (array $failure): array => [
                    $failure['tenant_id'],
                    $failure['slip_id'],
                    $failure['slip_no'],
                    $failure['reason'],
                ],
                $summary->failures,
            ));
        }

        return $summary->failures === [] ? self::SUCCESS : self::FAILURE;
    }
}
