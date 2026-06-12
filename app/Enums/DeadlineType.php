<?php

namespace App\Enums;

enum DeadlineType: string
{
    case DaysSinceArrival = 'days_since_arrival';

    /**
     * Anchored to the move-in date, not arrival — Anmeldung's legal clock
     * (§17 BMG) starts when you move in. While the user is in temporary
     * housing (no move-in date yet) the deadline is PAUSED, never overdue.
     */
    case DaysSinceMoveIn = 'days_since_move_in';

    /**
     * First-permit window: visa-free entrants get arrival + deadline_days
     * (90); D-visa holders have no computable date — the UI shows "before
     * your visa expires" instead.
     */
    case PermitWindow = 'permit_window';

    case FixedDate = 'fixed_date';
    case None = 'none';
}
