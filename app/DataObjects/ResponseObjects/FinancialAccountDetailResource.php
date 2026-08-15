<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;

class FinancialAccountDetailResource extends BaseDataObject
{
    public function __construct(
        public FinancialAccountResource $account,
        public array $assignedUsers,
    ) {}

    public function toArray(): array
    {
        return [
            ...$this->account->toArray(),
            'assigned_users' => array_map(fn ($user) => $user->toArray(), $this->assignedUsers),
        ];
    }
}
