<?php

namespace App\Enums;

enum DeadlineType: string
{
    case DaysSinceArrival = 'days_since_arrival';
    case FixedDate = 'fixed_date';
    case None = 'none';
}
