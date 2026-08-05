<?php

namespace App\Services\TenantModule;

use App\DataObjects\ResponseObjects\AccountingChangeRepairSummary;
use App\Models\CoreModule\TenantDebt;
use App\Models\PawnModule\PawnInterestPayment;
use App\Models\PawnModule\PawnRedemption;
use App\Repository\AccountingChangeRepairRepository;
use App\Support\TenantScopedCacheKeys;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class AccountingChangeRepairService
{
    private const CHUNK_SIZE = 500;

    private const REFERENCE_TYPES = [
        'interest' => [PawnInterestPayment::class, 'InterestPayment'],
        'debt' => [TenantDebt::class, 'Debt'],
        'redemption' => [PawnRedemption::class, 'Redemption'],
    ];

    public function __construct(
        private AccountingChangeRepairRepository $repository,
        private TenantScopedCacheKeys $tenantScopedCacheKeys,
    ) {
    }

    public function repair(bool $apply): AccountingChangeRepairSummary
    {
        $summary = new AccountingChangeRepairSummary();

        $this->processInterestPayments($summary, $apply);
        $this->processDebtPayments($summary, $apply);
        $this->processRedemptions($summary, $apply);

        return $summary;
    }

    private function processInterestPayments(AccountingChangeRepairSummary $summary, bool $apply): void
    {
        $this->repository->chunkInterestPayments(self::CHUNK_SIZE, function ($payments) use ($summary, $apply): void {
            foreach ($payments as $payment) {
                $summary->scanned++;
                $grossCents = $this->toCents($payment->payment_amount);
                $changeCents = $this->toCents($payment->change_amount);
                $expectedNetCents = $grossCents - $changeCents;
                $rows = $this->repository->activeAccountingRows(
                    (int) $payment->tenant_id,
                    self::REFERENCE_TYPES['interest'],
                    (int) $payment->id,
                );
                [$incomingRows, $outgoingRows] = $this->cashRows($rows);
                $incomingCents = $this->sumCents($incomingRows);
                $outgoingCents = $this->sumCents($outgoingRows);

                if ($incomingCents === $grossCents && $outgoingCents === $changeCents) {
                    $summary->alreadyCorrect++;
                    continue;
                }

                if (
                    $incomingCents === $expectedNetCents
                    && $outgoingRows->count() === 1
                    && $outgoingCents === $changeCents
                ) {
                    $this->softDeleteIncorrectChange($summary, $apply, 'interest', $payment, (int) $outgoingRows->first()->id);
                    continue;
                }

                $summary->recordSkip('interest', (int) $payment->tenant_id, (int) $payment->id, 'unexpected accounting pattern');
            }
        });
    }

    private function processDebtPayments(AccountingChangeRepairSummary $summary, bool $apply): void
    {
        $this->repository->chunkPaidDebts(self::CHUNK_SIZE, function ($debts) use ($summary, $apply): void {
            foreach ($debts as $debt) {
                $summary->scanned++;
                $collectibleCents = $this->toCents($debt->amount);
                $rows = $this->repository->activeAccountingRows(
                    (int) $debt->tenant_id,
                    self::REFERENCE_TYPES['debt'],
                    (int) $debt->id,
                );
                [$incomingRows, $outgoingRows] = $this->cashRows($rows, debtOnly: true);

                if ($incomingRows->count() !== 1) {
                    $summary->recordSkip('debt', (int) $debt->tenant_id, (int) $debt->id, 'ambiguous or multiple incoming payments');
                    continue;
                }

                $incomingCents = $this->sumCents($incomingRows);
                $outgoingCents = $this->sumCents($outgoingRows);

                if ($incomingCents === $collectibleCents && $outgoingCents === 0) {
                    $summary->alreadyCorrect++;
                    continue;
                }

                if ($incomingCents > $collectibleCents) {
                    $changeCents = $incomingCents - $collectibleCents;

                    if ($outgoingCents === $changeCents) {
                        $summary->alreadyCorrect++;
                        continue;
                    }

                    if ($outgoingRows->isEmpty()) {
                        $this->addMissingChange(
                            $summary,
                            $apply,
                            'debt',
                            $debt,
                            (string) $incomingRows->first()->reference_type,
                            $changeCents,
                        );
                        continue;
                    }
                }

                $summary->recordSkip('debt', (int) $debt->tenant_id, (int) $debt->id, 'unexpected accounting pattern');
            }
        });
    }

    private function processRedemptions(AccountingChangeRepairSummary $summary, bool $apply): void
    {
        $this->repository->chunkRedemptions(self::CHUNK_SIZE, function ($redemptions) use ($summary, $apply): void {
            foreach ($redemptions as $redemption) {
                $summary->scanned++;
                $grossCents = $this->toCents($redemption->received_amount);
                $changeCents = $this->toCents($redemption->change_amount);
                $expectedNetCents = $grossCents - $changeCents;
                $rows = $this->repository->activeAccountingRows(
                    (int) $redemption->tenant_id,
                    self::REFERENCE_TYPES['redemption'],
                    (int) $redemption->id,
                );
                [$incomingRows, $outgoingRows] = $this->cashRows($rows);
                $incomingCents = $this->sumCents($incomingRows);
                $outgoingCents = $this->sumCents($outgoingRows);

                if ($incomingCents === $grossCents && $outgoingCents === $changeCents) {
                    $summary->alreadyCorrect++;
                    continue;
                }

                if (
                    $incomingCents === $expectedNetCents
                    && $outgoingRows->count() === 1
                    && $outgoingCents === $changeCents
                ) {
                    $this->softDeleteIncorrectChange($summary, $apply, 'redemption', $redemption, (int) $outgoingRows->first()->id);
                    continue;
                }

                if ($incomingCents === $grossCents && $outgoingRows->isEmpty()) {
                    $referenceType = (string) ($incomingRows->first()?->reference_type ?? PawnRedemption::class);
                    $this->addMissingChange($summary, $apply, 'redemption', $redemption, $referenceType, $changeCents);
                    continue;
                }

                $summary->recordSkip('redemption', (int) $redemption->tenant_id, (int) $redemption->id, 'unexpected accounting pattern');
            }
        });
    }

    private function softDeleteIncorrectChange(
        AccountingChangeRepairSummary $summary,
        bool $apply,
        string $type,
        object $record,
        int $accountingId,
    ): void {
        if ($apply) {
            DB::transaction(fn () => $this->repository->softDeleteOutgoing((int) $record->tenant_id, $accountingId));
            $this->invalidateAccountingCaches((int) $record->tenant_id);
        }

        $summary->recordRepair((int) $record->tenant_id);
    }

    private function addMissingChange(
        AccountingChangeRepairSummary $summary,
        bool $apply,
        string $type,
        object $record,
        string $referenceType,
        int $changeCents,
    ): void {
        if ($apply) {
            DB::transaction(fn () => $this->repository->createOutgoingChange(
                (int) $record->tenant_id,
                $referenceType,
                (int) $record->id,
                $this->formatCents($changeCents),
                'Accounting Change Repair: Missing '.ucfirst($type).' Change',
            ));
            $this->invalidateAccountingCaches((int) $record->tenant_id);
        }

        $summary->recordRepair((int) $record->tenant_id);
    }

    /** @return array{Collection, Collection} */
    private function cashRows(Collection $rows, bool $debtOnly = false): array
    {
        $incoming = $rows->filter(fn ($row): bool => $this->normalizeType($row->transaction_type) === 'incoming')->values();
        $outgoing = $rows->filter(function ($row) use ($debtOnly): bool {
            if ($this->normalizeType($row->transaction_type) !== 'outgoing') {
                return false;
            }

            return ! $debtOnly || str_contains(strtolower((string) $row->description), 'change');
        })->values();

        return [$incoming, $outgoing];
    }

    private function normalizeType(string $type): string
    {
        return match (strtolower(trim($type))) {
            'income' => 'incoming',
            'expense' => 'outgoing',
            default => strtolower(trim($type)),
        };
    }

    private function sumCents(Collection $rows): int
    {
        return $rows->reduce(fn (int $sum, $row): int => $sum + $this->toCents($row->amount), 0);
    }

    private function toCents(string|int $amount): int
    {
        $value = trim((string) $amount);
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = substr(str_pad($fraction, 2, '0'), 0, 2);
        $cents = ((int) ($whole === '' ? '0' : $whole) * 100) + (int) $fraction;

        return $negative ? -$cents : $cents;
    }

    private function formatCents(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private function invalidateAccountingCaches(int $tenantId): void
    {
        foreach ([
            'tenant-accounting-list',
            'tenant-accounting-incoming-list',
            'tenant-accounting-outgoing-list',
            'tenant-accounting-overview',
        ] as $prefix) {
            $this->tenantScopedCacheKeys->bumpVersion($prefix, tenantId: $tenantId);
        }
    }
}
