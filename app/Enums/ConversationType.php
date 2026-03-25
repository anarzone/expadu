<?php

namespace App\Enums;

enum ConversationType: string
{
    case Language = 'language';
    case Event = 'event';
    case Direct = 'direct';
}
