<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Enums\FinancialUnit;

class FinancialUnitResource extends BaseDataObject
{
    public function __construct(
        public string $code,
        public string $labelEn,
        public string $labelMm,
        public int $multiplier,
    ) {}

    public static function fromEnum(FinancialUnit $unit): self
    {
        return new self($unit->value, $unit->labelEn(), $unit->labelMm(), $unit->multiplier());
    }

    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'label_en' => $this->labelEn,
            'label_mm' => $this->labelMm,
            'multiplier' => $this->multiplier,
        ];
    }
}
