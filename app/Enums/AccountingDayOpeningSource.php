<?php

namespace App\Enums;

enum AccountingDayOpeningSource: string
{
    case Manual = 'MANUAL';
    case FirstTransaction = 'FIRST_TRANSACTION';
    case Scheduled = 'SCHEDULED';
}
