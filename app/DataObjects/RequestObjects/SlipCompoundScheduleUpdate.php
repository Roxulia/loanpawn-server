<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class SlipCompoundScheduleUpdate extends BaseDataObject
{
    public function __construct(
        public int $slipUpdateKey,
        public bool $enabled,
        public ?int $compoundEvery,
        public ?string $compoundEveryType,
        public ?string $nextCompoundAt,
    ) {}
}
