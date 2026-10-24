<?php

namespace App\Services\TenantModule\Ownership;

use App\Exceptions\{InvalidTenantRequest, TenantNotFound};
use App\Models\OwnershipModule\OwnershipMonthlyCheckpoint;
use App\Repository\OwnershipRepository;
use App\Services\BaseTenantService;
use App\Support\OwnershipQuantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Atomically publishes supplied closings; monthly discovery/calculation remain OWN-27. */
class CheckpointService extends BaseTenantService
{
    public function __construct(private OwnershipRepository $repository) {}

    public function publishMonthlyOwnershipCheckpoint(int $ownedItemId, string $month, string $timezone, string $cutoffAt,
        int $watermark, array $balances): OwnershipMonthlyCheckpoint
    {
        // Validate metadata before entering the item publication transaction, as before.
        $cutoff = $this->validateCheckpointMetadata($month, $timezone, $cutoffAt, $watermark);
        return DB::transaction(function () use ($ownedItemId, $month, $timezone, $cutoff, $watermark, $balances): OwnershipMonthlyCheckpoint {
            $tenant = $this->resolveCurrentTenantId();
            $this->repository->lockAggregate($tenant, $ownedItemId) ?? throw new TenantNotFound(__('ownership.not_found'));
            $existing = $this->repository->findMonthlyOwnershipCheckpointForUpdate($tenant, $ownedItemId, $month.'-01');
            if ($existing !== null) return $existing;

            // Prepare exact tenant/item lot rows once, then persist and validate one aggregate result.
            $lots = $this->repository->lockedLots($tenant, $ownedItemId)->keyBy('id');
            $rows = $this->normalizeLotClosingBalances($lots, $balances);
            $checkpoint = $this->repository->createMonthlyOwnershipCheckpoint($tenant, $ownedItemId, $month.'-01',
                $timezone, $cutoff, $watermark, $rows);
            $facts = $this->repository->readOwnershipCheckpointBalanceFacts($tenant, (int) $checkpoint->id);
            if ($facts->has_overcommitted_lots) $this->rejectInconsistentCheckpoint();
            return $this->repository->saveOwnershipCheckpointTotals($checkpoint, $facts);
        });
    }

    private function validateCheckpointMetadata(string $month, string $timezone, string $cutoffAt, int $watermark): CarbonImmutable
    {
        // Preserve the existing month/cutoff/error contract and pinned tenant timezone semantics.
        if (! preg_match('/\A[0-9]{4}-(0[1-9]|1[0-2])\z/', $month) || $watermark < 0) $this->rejectInconsistentCheckpoint();
        try {
            $period = CarbonImmutable::createFromFormat('!Y-m-d', $month.'-01', $timezone);
            $cutoff = CarbonImmutable::parse($cutoffAt, 'UTC')->utc();
        } catch (Throwable) {
            $this->rejectInconsistentCheckpoint();
        }
        if (! $cutoff->equalTo($period->addMonth()->utc()) || $cutoff->isAfter(CarbonImmutable::now('UTC'))) $this->rejectInconsistentCheckpoint();
        return $cutoff;
    }

    private function normalizeLotClosingBalances(Collection $lots, array $balances): array
    {
        // Reject duplicate/foreign lots and normalize each fixed balance component without reference mutation.
        $seen = [];
        $rows = [];
        foreach ($balances as $row) {
            $id = (int) ($row['acquisition_lot_id'] ?? 0);
            if (! $lots->has($id) || isset($seen[$id])) $this->rejectInconsistentCheckpoint();
            $seen[$id] = true;
            foreach (['owned_quantity', 'reserved_quantity', 'pledged_quantity'] as $field) {
                $row[$field] = OwnershipQuantity::normalizeBalance((string) ($row[$field] ?? ''));
            }
            $rows[] = $row;
        }
        return $rows;
    }

    private function rejectInconsistentCheckpoint(): never
    {
        // Preserve localized errors without exposing database/date parsing details.
        throw new InvalidTenantRequest(__('ownership.summary_inconsistent'));
    }
}
