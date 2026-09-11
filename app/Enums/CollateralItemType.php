<?php

namespace App\Enums;

enum CollateralItemType: string
{
    case Jewellery = 'Jewellery';
    case Normal = 'Normal';
    case Pack = 'Pack of Jewellery';

    public static function normalize(string $value): ?self
    {
        foreach (self::cases() as $type) {
            if (strcasecmp(trim($value), $type->value) === 0) {
                return $type;
            }
        }

        return null;
    }
}
