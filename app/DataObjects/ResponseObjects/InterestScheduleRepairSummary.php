<?php

namespace App\DataObjects\ResponseObjects;

class InterestScheduleRepairSummary
{
    public int $scanned = 0;
    public int $repaired = 0;
    public int $alreadyCorrect = 0;
    public int $skippedWithoutPayment = 0;
    public int $skippedByStatus = 0;

    /** @var array<int, array{tenant_id: int, slip_id: int, slip_no: string, reason: string}> */
    public array $failures = [];

    public function recordFailure(int $tenantId, int $slipId, string $slipNo, string $reason): void
    {
        $this->failures[] = [
            'tenant_id' => $tenantId,
            'slip_id' => $slipId,
            'slip_no' => $slipNo,
            'reason' => $reason,
        ];
    }
}
