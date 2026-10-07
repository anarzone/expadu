<?php

namespace App\Enums;

enum DeadlineType: string
{
    case DaysSinceArrival = 'days_since_arrival';

    /**
     * Anchored to actual move-in, not arrival. Missing occupancy timing is
     * unknown; a temporary-housing label cannot prove that a clock is paused.
     */
    case DaysSinceMoveIn = 'days_since_move_in';

    /**
     * Compatibility clock for permit preparation. A D visa uses its known
     * expiry. Entry mode alone cannot establish a visa-free residence deadline.
     */
    case PermitWindow = 'permit_window';

    /**
     * Anchored to the task's life event using its reviewed interval.
     * The anchor date is the user's `{trigger_event}_at` attribute.
     */
    case DaysSinceEvent = 'days_since_event';

    /**
     * The deadline is the exact value of Task::deadline_fact_key. This is
     * used for visa/title expiries where adding an invented offset is unsafe.
     */
    case FactDate = 'fact_date';

    case FixedDate = 'fixed_date';
    case None = 'none';
}
