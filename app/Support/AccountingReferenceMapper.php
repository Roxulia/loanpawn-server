<?php

namespace App\Support;

use App\Models\CoreModule\TenantCapital;
use App\Models\CoreModule\TenantDebt;
use App\Models\CoreModule\TenantExpense;
use App\Models\PawnModule\PawnInterestPayment;
use App\Models\PawnModule\PawnLoanContractSlip;
use App\Models\PawnModule\PawnRedemption;
use Illuminate\Support\Str;

class AccountingReferenceMapper
{
    private const DASHBOARD_INCOME_REFERENCE_TYPES = [
        PawnInterestPayment::class,
    ];

    private const DASHBOARD_EXPENSE_REFERENCE_TYPES = [
        TenantExpense::class,
    ];

    private const DASHBOARD_NET_PROFIT_EXCLUDED_REFERENCE_TYPES = [
        TenantCapital::class,
    ];

    private const LABELS = [
        PawnLoanContractSlip::class => 'Loan Contract',
        PawnInterestPayment::class => 'Interest Payment',
        PawnRedemption::class => 'Redemption',
        TenantCapital::class => 'Capital',
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

    public static function dashboardIncomeReferenceTypes(): array
    {
        return self::DASHBOARD_INCOME_REFERENCE_TYPES;
    }

    public static function dashboardExpenseReferenceTypes(): array
    {
        return self::DASHBOARD_EXPENSE_REFERENCE_TYPES;
    }

    public static function dashboardNetProfitExcludedReferenceTypes(): array
    {
        return self::DASHBOARD_NET_PROFIT_EXCLUDED_REFERENCE_TYPES;
    }
}
