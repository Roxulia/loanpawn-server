<?php

namespace App\Enums;

enum AccountingDayClosingSource: string
{
    case Manual = 'MANUAL';
    case Scheduled = 'SCHEDULED';
    case PlatformMidnight = 'PLATFORM_MIDNIGHT';
    case CatchUp = 'CATCH_UP';
}
