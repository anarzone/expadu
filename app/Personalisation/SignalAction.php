<?php

namespace App\Personalisation;

/** What a person did with an item. Weights live in config/personalisation.php. */
enum SignalAction: string
{
    case Impression = 'impression';
    case DetailViewed = 'detail_viewed';
    case Saved = 'saved';
    case GetThere = 'get_there';
    case TripTaken = 'trip_taken';
    case PlanKeptUnedited = 'plan_kept_unedited';
    case Shared = 'shared';
    case StopSwappedOut = 'stop_swapped_out';
    case NotForMe = 'not_for_me';
    case NotNow = 'not_now';
    case MoreLikeThis = 'more_like_this';
    case BeenLiked = 'been_liked';
    case BeenDisliked = 'been_disliked';

    /**
     * The only actions a client may report (POST /api/signals): what the
     * server can't see. Everything else is recorded where it happens.
     *
     * @return list<string>
     */
    public static function fromClient(): array
    {
        // Taps only the client sees: "Not now" (hides 7 days), sharing, and "Not this" on a
        // suggested event (not for me, hides 90 days; a place's goes through its own feedback).
        return [self::Impression->value, self::DetailViewed->value, self::NotNow->value, self::Shared->value, self::NotForMe->value];
    }

    /**
     * The feedback a place carries at one time: a new choice replaces the old,
     * and clearing it withdraws it (so a cleared "Not for me" stops hiding).
     *
     * @return list<self>
     */
    public static function placeFeedback(): array
    {
        return [self::Saved, self::MoreLikeThis, self::NotForMe, self::BeenLiked, self::BeenDisliked];
    }

    public function hideDays(): ?int
    {
        $days = config("personalisation.actions.{$this->value}.hide_days");

        return is_int($days) ? $days : null;
    }
}
