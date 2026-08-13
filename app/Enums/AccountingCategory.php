<?php

namespace App\Enums;

enum AccountingCategory: string
{
    case Revenue = 'revenue';
    case Expense = 'expense';
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Internal = 'internal';
    case Adjustment = 'adjustment';
}
