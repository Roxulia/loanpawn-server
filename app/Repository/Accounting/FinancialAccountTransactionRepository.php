<?php

namespace App\Repository\Accounting;

use App\Models\FinancialAccountTransaction;

class FinancialAccountTransactionRepository
{
    public function create(array $data): FinancialAccountTransaction
    {
        return FinancialAccountTransaction::query()->create($data)->refresh();
    }
}
