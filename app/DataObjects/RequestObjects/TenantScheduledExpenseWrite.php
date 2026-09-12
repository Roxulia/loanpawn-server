<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class TenantScheduledExpenseWrite extends BaseDataObject
{
    public function __construct(
        public string $description,
        public float $amount,
        public int $accountId,
        public ?int $expenseTypeId,
        public string $recurrenceType,
        public string $startDate,
        public string $scheduledTime,
        public ?string $endDate,
        public ?int $weeklyDay,
        public ?int $monthlyAnchorDay,
        public int $updateKey = 0,
    ) {}
}
