<?php

namespace App\DataObjects\ResponseObjects;

class AccountingChangeRepairSummary
{
    public int $scanned = 0;
    public int $repaired = 0;
    public int $alreadyCorrect = 0;
    public int $skippedAmbiguous = 0;

    /** @var array<int, true> */
    public array $affectedTenantIds = [];

    /** @var array<int, array{type: string, tenant_id: int, reference_id: int, reason: string}> */
    public array $skippedItems = [];

    public function recordRepair(int $tenantId): void
    {
        $this->repaired++;
        $this->affectedTenantIds[$tenantId] = true;
    }

    public function recordSkip(string $type, int $tenantId, int $referenceId, string $reason): void
    {
        $this->skippedAmbiguous++;
        $this->skippedItems[] = [
            'type' => $type,
            'tenant_id' => $tenantId,
            'reference_id' => $referenceId,
            'reason' => $reason,
        ];
    }

    /** @return int[] */
    public function affectedTenantIdList(): array
    {
        $ids = array_keys($this->affectedTenantIds);
        sort($ids);

        return $ids;
    }
}
