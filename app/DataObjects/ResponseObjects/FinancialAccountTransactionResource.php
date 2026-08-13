<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\FinancialAccountTransaction;

class FinancialAccountTransactionResource extends BaseDataObject
{
    public function __construct(
        public int $id,
        public string $transactionType,
        public string $amount,
        public string $direction,
        public ?string $referenceNumber,
        public ?string $referenceType,
        public ?string $note,
        public ?array $creator,
        public ?int $relatedTransactionId,
        public ?int $reversedTransactionId,
        public ?string $createdAt,
    ) {}

    public static function fromModel(FinancialAccountTransaction $transaction): self
    {
        return new self(
            id: $transaction->id,
            transactionType: $transaction->transaction_type->value,
            amount: (string) $transaction->amount,
            direction: $transaction->direction,
            referenceNumber: $transaction->reference_number,
            referenceType: $transaction->reference_type,
            note: $transaction->note,
            creator: $transaction->creator === null ? null : [
                'id' => $transaction->creator->id,
                'name' => $transaction->creator->name,
            ],
            relatedTransactionId: $transaction->related_transaction_id,
            reversedTransactionId: $transaction->reversed_transaction_id,
            createdAt: $transaction->created_at?->toISOString(),
        );
    }
}
