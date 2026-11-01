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
}
