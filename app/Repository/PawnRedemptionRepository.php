<?php

namespace App\Repository;

use App\Models\PawnModule\PawnRedemption;
use App\Exceptions\RequiredValueMissing;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class PawnRedemptionRepository
{
    public function paginate(int $perPage = 15, ?CarbonImmutable $startDate = null, ?CarbonImmutable $endDate = null): LengthAwarePaginator
    {
        return PawnRedemption::query()
            ->with('slip')
            ->when($startDate !== null, fn ($query) => $query->whereDate('redemption_at', '>=', $startDate->toDateString()))
            ->when($endDate !== null, fn ($query) => $query->whereDate('redemption_at', '<=', $endDate->toDateString()))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function create(array $data): PawnRedemption
    {
        $this->requireValue($data, 'slip_number');

        return PawnRedemption::query()
            ->create($data)
            ->load('slip');
    }

    protected function requireValue(array $data, string $key): void
    {
        if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            throw new RequiredValueMissing("Pawn redemption {$key} is required.");
        }
    }

    public function findById(int $redemptionId): ?PawnRedemption
    {
        return PawnRedemption::query()
            ->with('slip')
            ->find($redemptionId);
    }

    public function findBySlipNumber(string $slipNumber): ?PawnRedemption
    {
        return PawnRedemption::query()
            ->with('slip')
            ->where('slip_number', $slipNumber)
            ->first();
    }

    /**
     * @return Collection<int, PawnRedemption>
     */
    public function getBySlipId(int $slipId): Collection
    {
        return PawnRedemption::query()
            ->where('slip_id', $slipId)
            ->orderByDesc('id')
            ->get();
    }
}
