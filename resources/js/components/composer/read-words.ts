/**
 * What the assistant looks at, by the names App\Composer\Assistant gives them
 * (an answer's `read`, the progress line's `looked`), in the person's words.
 */
export type Read =
    | 'location'
    | 'listings'
    | 'places'
    | 'weather'
    | 'departures'
    | 'notices'
    | 'trip'
    | 'planner'
    | 'guidance'
    | 'paperwork'
    | 'your_trip'
    | 'your_plans'
    | 'plans'
    | 'my_places'
    | 'taste';

const WORDS: Record<Read, string> = {
    location: 'where you are',
    listings: 'the listings',
    places: 'places',
    weather: 'the weather',
    departures: 'departures',
    notices: 'KVB notices',
    trip: 'the way there',
    planner: 'the trip planner',
    guidance: 'reviewed guides',
    paperwork: 'your Paperwork',
    your_trip: 'your trip',
    your_plans: 'your plans',
    plans: 'your plans',
    my_places: 'your places',
    taste: 'what you like',
};

/**
 * "the listings, places and the weather": the names it knows, in one order,
 * each said once; a name it doesn't know is left out.
 */
export function readList(
    keys: readonly string[],
    words: Partial<Record<Read, string>> = {},
): string {
    const named = [
        ...new Set(
            (Object.keys(WORDS) as Read[])
                .filter((key) => keys.includes(key))
                .map((key) => words[key] ?? WORDS[key]),
        ),
    ];

    return named.length > 1
        ? `${named.slice(0, -1).join(', ')} and ${named.at(-1)}`
        : (named[0] ?? '');
}
