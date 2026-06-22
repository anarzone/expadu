<?php

namespace App\Enums;

use App\Transit\TravelTimes;

/**
 * The user's default way of getting around. Drives the mode for the Places
 * "min away" number, the composer's travel times, and the take-me-there
 * default. A null preference means "fastest realistic" — see
 * {@see TravelTimes}.
 */
enum TransportMode: string
{
    case Transit = 'transit';
    case Bike = 'bike';
    case Walk = 'walk';
}
