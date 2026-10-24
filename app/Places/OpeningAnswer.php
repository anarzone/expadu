<?php

namespace App\Places;

use App\Composer\Candidate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * "Open now" only from a place's own listed hours. Composer fills in category
 * defaults to lay out a day (hoursAssumed), and a place with no listed hours
 * gets no window at all; neither may be told to someone as open.
 */
class OpeningAnswer
{
    /** true/false from listed hours; null when its hours aren't listed. */
    public static function isOpenAt(Candidate $place, CarbonImmutable $at): ?bool
    {
        if ($place->hoursAssumed || ($place->placeFacts['hours']['status'] ?? 'unknown') !== 'known') {
            return null;
        }
        if ($place->closedToday) {
            return false;
        }
        if ($place->opensAt === null && $place->closesAt === null) {
            return true;
        }

        return ($place->opensAt === null || $place->opensAt->lessThanOrEqualTo($at))
            && ($place->closesAt === null || $place->closesAt->greaterThan($at));
    }

    /**
     * Why an "open at" ask found nothing among places that exist, or null when
     * some are open. Says "closed on Sundays" rather than "nothing listed".
     *
     * @param  Collection<int, Candidate>  $places  the matches before the opening filter
     */
    public static function whyNoneOpen(Collection $places, CarbonImmutable $at, string $kind): ?string
    {
        if ($places->isEmpty()) {
            return null;
        }
        $answers = $places->map(fn (Candidate $place): ?bool => self::isOpenAt($place, $at));
        if ($answers->contains(true)) {
            return null;
        }
        $known = $answers->filter(fn (?bool $open): bool => $open !== null)->count();
        $local = $at->setTimezone('Europe/Berlin');
        if ($known === 0) {
            return "{$places->count()} {$kind} nearby, but none has its opening hours listed, so whether they're open isn't known.";
        }
        $closedAllDay = $places->filter(fn (Candidate $place): bool => self::isOpenAt($place, $at) === false && $place->closedToday)->count();
        if ($closedAllDay === $known) {
            return $local->isSunday()
                ? "The {$kind} nearby with listed hours are closed on Sundays."
                : "The {$kind} nearby with listed hours are closed all day on {$local->format('l')}.";
        }

        return "None of the {$kind} nearby with listed hours is open at {$local->format('H:i')}.".($known < $places->count() ? ' Some have no hours listed.' : '');
    }
}
