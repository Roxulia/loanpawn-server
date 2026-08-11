<?php

namespace App\Repository\Accounting;

use App\Models\FinancialAccount;

class MultiAccountRepository
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }

    public function getAccountsByCurrency(string $currencyCode): array
    {
        // Implement the logic to retrieve accounts by currency code
        return FinancialAccount::query()
            ->where('currency_code', $currencyCode)
            ->get()
            ->toArray();
    }
}
