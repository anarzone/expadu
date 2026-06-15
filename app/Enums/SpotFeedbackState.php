<?php

namespace App\Enums;

/**
 * A user's standing relationship to a place. "More like this" / "Save" are
 * forward-looking interest signals (no visit implied — that's the whole point);
 * "Been" / "Not interested" remove the place from discovery.
 */
enum SpotFeedbackState: string
{
    case MoreLikeThis = 'more_like_this';
    case Saved = 'saved';
    case Been = 'been';
    case NotInterested = 'not_interested';

    /**
     * States that drop a spot out of the discovery rails — you've either
     * "consumed" it (been) or rejected it (not interested).
     *
     * @return list<self>
     */
    public static function hiddenFromDiscovery(): array
    {
        return [self::Been, self::NotInterested];
    }
}
