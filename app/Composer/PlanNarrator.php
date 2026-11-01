<?php

namespace App\Composer;

/**
 * A templated, deterministic "why" line for a plan slot — what the LLM
 * narrative would later phrase as prose, but built from data we already have
 * so the plan reads as intentional (not a random list) without any model call.
 */
class PlanNarrator
{
    public static function for(PlanSlot $slot, ?PlanSlot $previous, bool $rain): string
    {
        $candidate = $slot->candidate;

        if ($candidate->isAppointment()) {
            $leaveBy = $slot->travelMinFromPrevious > 0
                ? $slot->startAt->subMinutes($slot->travelMinFromPrevious)->format('H:i')
                : null;

            return $leaveBy !== null && $previous !== null
                ? "Leave {$previous->candidate->name} by {$leaveBy}"
                : 'Your appointment — not movable';
        }

        $parts = [];

        if ($candidate->isLandmark) {
            $parts[] = '⭐ landmark';
        }

        // Only a place mapped as indoor or covered beats the rain; a category
        // that's usually indoors isn't a fact about this place.
        if ($rain && self::mappedIndoor($candidate)) {
            $parts[] = 'indoor — beats the rain';
        }

        // Between stops the minutes are a straight-line estimate, never a route:
        // said as one, and on foot only where the estimate is a walk.
        if ($previous !== null && $slot->travelMinFromPrevious > 0) {
            $sameArea = $previous->candidate->veedel !== null
                && $previous->candidate->veedel === $candidate->veedel;
            $onFoot = TravelEstimator::walks((new TravelEstimator)->haversineKm(
                $previous->candidate->lat, $previous->candidate->lng, $candidate->lat, $candidate->lng,
            ));
            $about = "about {$slot->travelMinFromPrevious} min";
            $parts[] = match (true) {
                $onFoot && $sameArea => "{$about} on foot · same area",
                $onFoot => "{$about} on foot",
                default => "{$about} away",
            };
        }

        if ($candidate->type === 'spot' && (($candidate->placeFacts['hours']['status'] ?? null) === 'unknown' || $candidate->hoursAssumed)) {
            $parts[] = 'Opening hours unconfirmed';
        }

        // Only verified opening hours are stated as fact — assumed category
        // defaults shape the plan but are never claimed to the user.
        if ($candidate->closesAt !== null && ! $candidate->hoursAssumed) {
            $parts[] = 'open till '.$candidate->closesAt->format('H:i');
        }

        return implode(' · ', $parts);
    }

    /** The place's own facts say indoor or covered (OSM indoor=yes, covered=yes). */
    private static function mappedIndoor(Candidate $candidate): bool
    {
        $practical = $candidate->placeFacts['practical'] ?? [];

        foreach (['indoor', 'covered'] as $key) {
            if (($practical[$key]['status'] ?? null) === 'known' && in_array(strtolower((string) ($practical[$key]['value'] ?? '')), ['yes', 'true', '1'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Annotate every slot with its "why" line, given the full ordered sequence.
     * Used both for the initial plan and after a swap, so the lines never
     * silently vanish.
     *
     * @param  list<PlanSlot>  $slots
     * @return list<PlanSlot>
     */
    public static function narrate(array $slots, bool $rain): array
    {
        $previous = null;
        foreach ($slots as $i => $slot) {
            $slots[$i] = new PlanSlot(
                $slot->candidate,
                $slot->startAt,
                $slot->endAt,
                $slot->travelMinFromPrevious,
                self::for($slot, $previous, $rain),
            );
            $previous = $slot;
        }

        return $slots;
    }
}
