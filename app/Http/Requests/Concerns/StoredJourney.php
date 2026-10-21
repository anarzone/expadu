<?php

namespace App\Http\Requests\Concerns;

use App\Transit\DepartureTime;
use Illuminate\Validation\Rule;

/**
 * A journey a person keeps (a started trip, a planned way) stays a journey:
 * the adapters' leg modes, bounded stops and shapes, a bounded size, and
 * times that run forward.
 */
final class StoredJourney
{
    /** The modes a journey leg can have (as the App\Transit adapters map them). */
    public const MODES = ['walk', 'bike', 'bus', 'tram', 'subway', 'rail', 'ferry'];

    public const MAX_BYTES = 262144;

    public const MAX_STOPS_PER_LEG = 200;

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'journey' => ['required', 'array'],
            'journey.depart_at' => ['required', 'string', 'max:40'],
            'journey.arrive_at' => ['required', 'string', 'max:40'],
            'journey.legs' => ['required', 'array', 'min:1', 'max:40'],
            'journey.legs.*' => ['required', 'array'],
            'journey.legs.*.mode' => ['required', 'string', Rule::in(self::MODES)],
            'journey.legs.*.intermediate_stops' => ['sometimes', 'nullable', 'array', 'max:'.self::MAX_STOPS_PER_LEG],
            'journey.legs.*.polyline' => ['sometimes', 'nullable', 'string', 'max:100000'],
            'journey.legs.*.depart_at' => ['required', 'string', 'max:40'],
            'journey.legs.*.arrive_at' => ['required', 'string', 'max:40'],
            'journey.legs.*.to.name' => ['sometimes', 'nullable', 'string', 'max:300'],
        ];
    }

    /**
     * What's wrong with it, in the person's words, or null.
     *
     * @param  array<string, mixed>  $journey
     */
    public static function problem(array $journey): ?string
    {
        if (strlen((string) json_encode($journey)) > self::MAX_BYTES) {
            return 'This route is too large to keep. Find a fresh route.';
        }

        $depart = DepartureTime::parse($journey['depart_at']);
        $arrive = DepartureTime::parse($journey['arrive_at']);
        if ($depart === null || $arrive === null || $arrive->lessThanOrEqualTo($depart)) {
            return 'This route has invalid times. Find a fresh route.';
        }

        $previous = $depart;
        foreach ($journey['legs'] as $leg) {
            $legDepart = DepartureTime::parse($leg['depart_at']);
            $legArrive = DepartureTime::parse($leg['arrive_at']);
            if ($legDepart === null || $legArrive === null || $legDepart->lessThan($previous)
                || $legArrive->lessThan($legDepart) || $legArrive->greaterThan($arrive)) {
                return 'A journey step has invalid times. Find a fresh route.';
            }
            $previous = $legArrive;
        }

        return null;
    }
}
