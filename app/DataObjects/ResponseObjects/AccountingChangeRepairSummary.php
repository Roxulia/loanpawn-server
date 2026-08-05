<?php

namespace App\DataObjects\ResponseObjects;

class AccountingChangeRepairSummary
{
    private const TYPES = ['interest', 'debt', 'redemption'];

    public int $scanned = 0;
    public int $repaired = 0;
    public int $alreadyCorrect = 0;
    public int $skippedAmbiguous = 0;

    /** @var array<int, true> */
    public array $affectedTenantIds = [];

    /** @var array<int, array{type: string, tenant_id: int, reference_id: int, reason: string}> */
    public array $skippedItems = [];

    /** @var array<string, array{scanned: int, repaired: int, already_correct: int, skipped: int}> */
    public array $byType = [
        'interest' => ['scanned' => 0, 'repaired' => 0, 'already_correct' => 0, 'skipped' => 0],
        'debt' => ['scanned' => 0, 'repaired' => 0, 'already_correct' => 0, 'skipped' => 0],
        'redemption' => ['scanned' => 0, 'repaired' => 0, 'already_correct' => 0, 'skipped' => 0],
    ];

    public function recordScanned(string $type): void
    {
        $this->ensureKnownType($type);
        $this->scanned++;
        $this->byType[$type]['scanned']++;
    }

    public function recordRepair(string $type, int $tenantId): void
    {
        $this->ensureKnownType($type);
        $this->repaired++;
        $this->byType[$type]['repaired']++;
        $this->affectedTenantIds[$tenantId] = true;
    }

    public function recordAlreadyCorrect(string $type): void
    {
        $this->ensureKnownType($type);
        $this->alreadyCorrect++;
        $this->byType[$type]['already_correct']++;
    }

    public function recordSkip(string $type, int $tenantId, int $referenceId, string $reason): void
    {
        $this->ensureKnownType($type);
        $this->skippedAmbiguous++;
        $this->byType[$type]['skipped']++;
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

    private function ensureKnownType(string $type): void
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Unknown accounting repair type: {$type}");
        }
    }
}
