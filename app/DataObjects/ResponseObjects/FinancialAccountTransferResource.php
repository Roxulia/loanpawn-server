<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\FinancialAccountsTranfers;

class FinancialAccountTransferResource extends BaseDataObject
{
    public function __construct(
        public int $id,
        public array $fromAccount,
        public array $toAccount,
        public string $fromAmount,
        public string $toAmount,
        public ?string $exchangeRate,
        public string $feeAmount,
        public ?string $note,
        public ?string $transferredAt,
    ) {}

    public static function fromModel(FinancialAccountsTranfers $transfer): self
    {
        return new self(
            id: $transfer->id,
            fromAccount: self::accountData($transfer->fromAccount),
            toAccount: self::accountData($transfer->toAccount),
            fromAmount: (string) $transfer->from_amount,
            toAmount: (string) $transfer->to_amount,
            exchangeRate: $transfer->exchange_rate === null ? null : (string) $transfer->exchange_rate,
            feeAmount: (string) $transfer->fee_amount,
            note: $transfer->note,
            transferredAt: $transfer->transferred_at?->toISOString(),
        );
    }

    private static function accountData($account): array
    {
        return [
            'id' => $account->id,
            'code' => $account->account_code,
            'name' => $account->account_name,
            'balance' => (string) $account->balance,
            'currency' => ['id' => $account->currency->id, 'code' => $account->currency->code, 'symbol' => $account->currency->symbol],
        ];
    }
}
