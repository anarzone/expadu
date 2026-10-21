<?php

namespace App\Personalisation;

enum SignalItemType: string
{
    case Spot = 'spot';
    case Event = 'event';
    case Plan = 'plan';
    case Journey = 'journey';

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
