<?php

namespace App\DataObjects\RequestObjects;

class DebtCompoundScheduleUpdate
{
    public function __construct(
        public int $debtUpdateKey,
        public bool $enabled,
        public ?int $compoundEvery,
        public ?string $compoundEveryType,
        public ?string $nextCompoundAt,
    ) {}
}
