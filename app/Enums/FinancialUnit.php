<?php

namespace App\Enums;

enum FinancialUnit: string
{
    case Unit = 'UNIT';
    case Thousand = 'THOUSAND';
    case Lakh = 'LAKH';
    case Million = 'MILLION';
    case Crore = 'CRORE';
    case Billion = 'BILLION';

    public function multiplier(): int
    {
        return match ($this) {
            self::Unit => 1,
            self::Thousand => 1_000,
            self::Lakh => 100_000,
            self::Million => 1_000_000,
            self::Crore => 10_000_000,
            self::Billion => 1_000_000_000,
        };
    }

    public function labelEn(): string
    {
        return match ($this) {
            self::Unit => 'Unit',
            self::Thousand => 'Thousand',
            self::Lakh => 'Lakh',
            self::Million => 'Million',
            self::Crore => 'Crore',
            self::Billion => 'Billion',
        };
    }

    public function labelMm(): string
    {
        return match ($this) {
            self::Unit => 'ယူနစ်',
            self::Thousand => 'ထောင်',
            self::Lakh => 'သိန်း',
            self::Million => 'သန်း',
            self::Crore => 'ကုဋေ',
            self::Billion => 'ဘီလီယံ',
        };
    }
}
