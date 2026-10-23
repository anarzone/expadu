/* A place as Around here lists it, and what its row says about it (the
   mock's connected/places.js: hours, distance and the facts a listing
   records). A fact the listing doesn't record is null and isn't shown;
   nothing is guessed. */

export type PlaceIconName =
    | 'pharmacy'
    | 'cafe'
    | 'bakery'
    | 'grocery'
    | 'playground'
    | 'park'
    | 'nature'
    | 'library'
    | 'restaurant'
    | 'fastfood'
    | 'culture'
    | 'sports';

export type ListedHours =
    | { state: 'open'; until: string }
    | { state: 'closed'; opens: string }
    | { state: 'closed'; today: boolean }
    | { state: 'unknown' };

export type AroundPlace = {
    id: number;
    name: string;
    kind: string;
    icon: PlaceIconName | '';
    label: string;
    veedel: string | null;
    lat: number;
    lng: number;
    /** From the point searched around; null when that isn't known. */
    km: number | null;
    /** A park or a wood is measured to its middle. */
    area: boolean;
    hours: ListedHours;
    wheelchair: string | null;
    outdoor_seating: string | null;
    cuisine: string | null;
    diets: { vegetarian?: string; vegan?: string };
    internet_access: string | null;
    /** A sports centre's sports as listed ("fencing;tai_chi"); null when not. */
    sport?: string | null;
    /** The street from its listed address, when listed. */
    street: string | null;
    /** A rights-cleared photo with its credit; null for most places (no placeholder). */
    photo: {
        url: string;
        credit: string | null;
        source_url: string | null;
        licence_url: string | null;
    } | null;
    /** For a park answered for its facilities: the kinds that fitted the ask ("Football pitch"). */
    matched?: string[];
    /** What a park has, the matched kind first: "Football pitch · 2 tennis courts · Playground". */
    facilities?: string | null;
    /** A park too big to walk round answers per Veedel: the part this row is. */
    part?: string | null;
    saved: boolean;
};

/** A row's own key: one park can list once per part, under its one id. */
export const rowKey = (place: AroundPlace) => `${place.id}-${place.part ?? ''}`;

export type AroundKind = { id: string; label: string };

/** Places to eat, whose rows say their diets. */
const EATING = ['cafe', 'restaurant', 'fastfood', 'bakery'];

/** "Open until 18:30", "Opens at 15:00", "Closed for the day", "Hours not
    listed": what the listing says at the time, never a live check. */
export function hoursText(hours: ListedHours): string {
    if (hours.state === 'open') {
        return `Open until ${['24:00', '00:00'].includes(hours.until) ? 'midnight' : hours.until}`;
    }

    if (hours.state === 'unknown') {
        return 'Hours not listed';
    }

    if ('opens' in hours) {
        return `Opens at ${hours.opens}`;
    }

    return hours.today ? 'Closed for the day' : 'Closed today';
}

/** "50 m", "about 600 m" for the middle of an area, "1.4 km", "12 km". */
export function distanceText(place: Pick<AroundPlace, 'km' | 'area'>): string {
    if (place.km === null) {
        return '';
    }

    // Rounded before it's said in m or km: 999.6 m is "1.0 km", 9.97 km "10 km".
    const metres = Math.round(place.km * 20) * 50;
    const tenths = Math.round(place.km * 10) / 10;
    const text =
        metres < 1000
            ? `${Math.max(50, metres)} m`
            : `${tenths < 10 ? tenths.toFixed(1) : Math.round(place.km)} km`;

    return place.area ? `about ${text}` : text;
}

const DIET_WORD = { vegetarian: 'vegetarian', vegan: 'vegan' } as const;

/** One short chip: "Vegetarian & vegan options", "Vegan only". */
export function dietChip(place: AroundPlace): string {
    if (!EATING.includes(place.icon)) {
        return '';
    }

    const capital = (text: string) =>
        text.replace(/^./, (c) => c.toUpperCase());
    const options = (Object.keys(DIET_WORD) as (keyof typeof DIET_WORD)[])
        .map((key) => {
            const word = DIET_WORD[key];

            return (
                (
                    {
                        yes: `${capital(word)} options`,
                        only: `${capital(word)} only`,
                        limited: `Some ${word} options`,
                    } as Record<string, string>
                )[place.diets[key] ?? ''] ?? null
            );
        })
        .filter((text): text is string => !!text);

    if (
        options.length > 1 &&
        options.every((text) => text.endsWith(' options')) &&
        options.every((text) => !text.startsWith('Some'))
    ) {
        return `${options
            .map((text, i) =>
                i
                    ? text.replace(/ options$/, '').toLowerCase()
                    : text.replace(/ options$/, ''),
            )
            .join(' & ')} options`;
    }

    return options[0] ?? '';
}

/** Places people go to sit and work, whose rows say Wi-Fi when listed. */
const NET = ['cafe', 'coworking', 'library'];

/**
 * A café's, a coworking space's or a library's Wi-Fi (a library's public
 * computers too), when listed: the values search counts as Wi-Fi
 * (PlaceAmenities::has), never a guess.
 */
export function placeNet(place: AroundPlace): string {
    if (!NET.includes(place.kind) && !NET.includes(place.icon)) {
        return '';
    }

    return (
        (
            {
                wlan: 'Wi-Fi',
                yes: 'Wi-Fi',
                wifi: 'Wi-Fi',
                terminal: place.icon === 'library' ? 'Public computers' : '',
            } as Record<string, string>
        )[(place.internet_access ?? '').toLowerCase()] ?? ''
    );
}

const CUISINE: Record<string, string> = {
    bagel: 'Bagels',
    breakfast: 'Breakfast',
    burger: 'Burgers',
    cake: 'Cake',
    coffee_shop: 'Coffee',
    crepe: 'Crêpes',
    friture: 'Chips',
    ice_cream: 'Ice cream',
    kebab: 'Kebab',
    italian_pizza: 'Pizza',
    noodle: 'Noodles',
    sandwich: 'Sandwiches',
    seafood: 'Seafood',
    steak_house: 'Steak',
};

/** A sports centre's sports in words: "Fencing, tai chi, qi gong" ('' when none). */
export function sportNames(raw: string | null | undefined): string {
    return (raw ?? '')
        .split(';')
        .map((value) => value.trim().replace(/_/g, ' '))
        .filter(Boolean)
        .join(', ')
        .replace(/^./, (c) => c.toUpperCase());
}

/** A listing's cuisine values in words: "Ice cream · Coffee" ('' when none). */
export function cuisineNames(raw: string | null): string {
    return (raw ?? '')
        .split(';')
        .map((value) => value.trim().toLowerCase())
        .filter(Boolean)
        .map(
            (value) =>
                CUISINE[value] ??
                value.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase()),
        )
        .filter((name, i, all) => all.indexOf(name) === i)
        .slice(0, 3)
        .join(' · ');
}

/** What the count calls these places: "3 pharmacies within 2 km". */
export function countNoun(
    kind: string,
    kinds: AroundKind[],
    one: boolean,
): string {
    const many =
        kind === 'green'
            ? 'green spaces'
            : kind === 'fastfood'
              ? 'fast food places'
              : kind
                ? (kinds.find((k) => k.id === kind)?.label.toLowerCase() ??
                  'places')
                : 'places';

    return one ? many.replace(/ies$/, 'y').replace(/s$/, '') : many;
}
