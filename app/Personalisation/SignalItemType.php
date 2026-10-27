<?php

namespace App\Personalisation;

enum SignalItemType: string
{
    case Spot = 'spot';
    case Event = 'event';
    case Plan = 'plan';
    case Journey = 'journey';
    // Under the second wording (PersonalisationWording, 26 Oct 2026): what they looked at that isn't a catalogue item.
    /** A kind of place or event, and its Veedel, Composer looked up for them (never their words). */
    case Topic = 'topic';
    /** A KVB stop they picked in Travel (never one near where they are, Home or an address). */
    case Stop = 'stop';
    /** A line they rode or looked up from a stop they picked. */
    case Line = 'line';
    /** An item of Today's "Needs you", by its kind (never Paperwork). */
    case Need = 'need';

    /**
     * A Composer / Day item id ("spot:12", "event:34" or "event:34:1760000000")
     * as a catalogue item; null for appointments, personal plans and anything else.
     *
     * @return array{0: self, 1: int}|null
     */
    public static function parse(mixed $id): ?array
    {
        if (! is_string($id) || ! preg_match('/^(spot|event):([1-9][0-9]{0,17})(?::[0-9]+)?$/D', $id, $match)) {
            return null;
        }

        return [self::from($match[1]), (int) $match[2]];
    }
}
