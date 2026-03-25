<?php

namespace App\Enums;

enum PartnerStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
}
