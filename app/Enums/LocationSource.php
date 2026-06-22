<?php

namespace App\Enums;

/**
 * Where a resolved origin came from. Drives whether we show a distance at all
 * (None → a "set your location" nudge instead of a guessed number) and how the
 * shared "From" control labels itself.
 */
enum LocationSource: string
{
    /** A fresh GPS fix passed in the request. */
    case Live = 'live';

    /** The explicit "I'm here" confirmation. */
    case Confirmed = 'confirmed';

    /** A recent background GPS ping. */
    case Ping = 'ping';

    /** A Veedel centroid — either explicitly picked or the area being browsed. */
    case Area = 'area';

    /** Nothing known — callers must not invent an origin. */
    case None = 'none';

    public function isKnown(): bool
    {
        return $this !== self::None;
    }
}
