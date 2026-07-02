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

        if (! $candidate->outdoor && $rain) {
            $parts[] = 'indoor — beats the rain';
        }

        if ($previous !== null && $slot->travelMinFromPrevious > 0) {
            $sameArea = $previous->candidate->veedel !== null
                && $previous->candidate->veedel === $candidate->veedel;
            $parts[] = $sameArea
                ? "🚶 {$slot->travelMinFromPrevious} min · same area"
                : "🚶 {$slot->travelMinFromPrevious} min away";
        }

        // Only verified opening hours are stated as fact — assumed category
        // defaults shape the plan but are never claimed to the user.
        if ($candidate->closesAt !== null && ! $candidate->hoursAssumed) {
            $parts[] = 'open till '.$candidate->closesAt->format('H:i');
        }

        return implode(' · ', $parts);
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
