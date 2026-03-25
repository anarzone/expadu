<?php

namespace App\Enums;

enum AlertType: string
{
    case System = 'system';
    case Social = 'social';
    case Reminder = 'reminder';
}
