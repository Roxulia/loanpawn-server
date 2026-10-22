<?php

namespace App\Services\TenantModule\Ownership;

use App\Repository\OwnershipRepository;
use App\Services\BaseTenantService;
use App\Support\InventoryQuantity;
use Carbon\CarbonImmutable;

/** Exact current lot balances after the caller acquires the shared aggregate anchor. */
class BalanceService extends BaseTenantService
{
    public function __construct(private OwnershipRepository $repository) {}

    public function locked(int $itemId, iterable $lots): array
    {
        // Current locking base/tail reads avoid stale repeatable-read snapshots established by callers.
        [$base, $tail] = $this->repository->lockedBalanceParts($this->resolveCurrentTenantId(), $itemId, CarbonImmutable::now('UTC')->toDateTimeString());
        $result = [];
        foreach ($lots as $lot) {
            $row = $base->get($lot->id);
            $result[$lot->id] = ['owned' => $this->scaled($row?->owned_quantity ?? '0.000'),
                'reserved' => $this->scaled($row?->reserved_quantity ?? '0.000'),
                'pledged' => $this->scaled($row?->pledged_quantity ?? '0.000')];
        }
        foreach ($tail as $movement) {
            if (! isset($result[$movement->acquisition_lot_id])) continue;
            foreach (['owned', 'reserved', 'pledged'] as $kind) {
                $result[$movement->acquisition_lot_id][$kind] += $this->scaled($movement->{$kind.'_delta'});
            }
        }
        foreach ($result as &$row) $row['available'] = $row['owned'] - $row['reserved'] - $row['pledged'];
        return $result;
    }

    private function scaled(string $value): int
    {
        // Only signed stored deltas use a sign; user quantities still use the positive parser.
        return (str_starts_with($value, '-') ? -1 : 1) * InventoryQuantity::parseBalance(ltrim($value, '-'));
    }
}
