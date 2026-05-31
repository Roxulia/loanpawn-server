<?php

namespace App\Support;

use App\Models\CoreModule\TenantDebt;
use App\Models\CoreModule\TenantExpense;
use App\Models\PawnModule\PawnInterestPayment;
use App\Models\PawnModule\PawnLoanContractSlip;
use App\Models\PawnModule\PawnRedemption;
use Illuminate\Support\Str;

class AccountingReferenceMapper
{
    private const LABELS = [
        PawnLoanContractSlip::class => 'Loan Contract',
        PawnInterestPayment::class => 'Interest Payment',
        PawnRedemption::class => 'Redemption',
        TenantDebt::class => 'Debt',
        TenantExpense::class => 'Expense',
    ];

    public static function label(?string $referenceType): ?string
    {
        if ($referenceType === null || trim($referenceType) === '') {
            return null;
        }

        if (isset(self::LABELS[$referenceType])) {
            return self::LABELS[$referenceType];
        }

        return Str::headline(class_basename($referenceType));
    }
}
