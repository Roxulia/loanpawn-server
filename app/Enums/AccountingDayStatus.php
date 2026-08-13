<?php

namespace App\Enums;

enum AccountingDayStatus: string
{
    case NotOpened = 'NOT_OPENED';
    case Open = 'OPEN';
    case Closing = 'CLOSING';
    case Closed = 'CLOSED';
}
