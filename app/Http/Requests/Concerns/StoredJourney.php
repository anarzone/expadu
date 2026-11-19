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
     * A way whose late ride now gets in after the next one leaves (TripLive), in words: which change it
     * misses and why, so they pick another way knowing what happened. Read from its rides' times, so it
     * holds whatever the app sends back.
     *
     * @param  array<string, mixed>  $journey
     */
    public static function missed(array $journey): ?string
    {
        $previous = null;
        foreach ($journey['legs'] ?? [] as $leg) {
            if (! is_array($leg) || in_array($leg['mode'] ?? 'walk', ['walk', 'bike'], true) || ! filled($leg['line'] ?? null)) {
                continue;
            }
            $depart = DepartureTime::parse($leg['depart_at'] ?? null);
            $in = $previous === null ? null : DepartureTime::parse($previous['arrive_at'] ?? null);
            if ($depart !== null && $in !== null && $in->greaterThan($depart)) {
                $late = (int) ($previous['delay_min'] ?? 0);
                $city = (string) config('geocoding.search_default.city', 'Köln');
                $stop = trim((string) preg_replace('/^'.preg_quote($city, '/').'\s+/u', '', (string) ($leg['from']['name'] ?? '')));

                return "The {$previous['line']} is running ".($late > 0 ? "{$late} min " : '')."late, so this way misses the {$leg['line']}"
                    .($stop !== '' ? " at {$stop}" : '').'. Pick another way.';
            }
            $previous = $leg;
        }

        return null;
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

        if (($missed = self::missed($journey)) !== null) {
            return $missed;
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
