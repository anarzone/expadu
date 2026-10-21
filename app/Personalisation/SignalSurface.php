<?php

namespace App\Personalisation;

/** Where in the app the action happened. */
enum SignalSurface: string
{
    case Today = 'today';
    case Places = 'places';
    case Events = 'events';
    case Composer = 'composer';
    case Travel = 'travel';
    case Search = 'search';
    case Alerts = 'alerts';
    case PlaceSheet = 'place_sheet';
    case EventSheet = 'event_sheet';
}
