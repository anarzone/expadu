/* The place icons, copied verbatim from the mock's connected/places.js
   (stroked, 24px): one per kind of place, and the facts a row shows. Static
   artwork only. */

const SHAPES = {
    pharmacy: '<path d="M3 5h18v16H3zM8 5V2h8v3M12 9v8M8 13h8"/>',
    cafe: '<path d="M3 8h14v7a5 5 0 0 1-5 5H8a5 5 0 0 1-5-5V8Zm14 1h2a3 3 0 0 1 0 6h-2M6 2v3m5-3v3"/>',
    bakery: '<path d="M4 15c-1-4 2-8 8-8s9 4 8 8c-1 3-4 4-8 4s-7-1-8-4Z"/><path d="m9 8 2 10m4-10-2 10"/>',
    grocery: '<path d="M3 8h18l-2 12H5L3 8Zm5 0 3-5m5 5-3-5M9 12v5m6-5v5"/>',
    playground: '<path d="M4 21V9l5-5 5 5v12M4 13h10m4 8V11m-4 10 7-10"/>',
    park: '<path d="M12 22V12m0 0c-4 0-7-3-7-7 4 0 7 3 7 7Zm0 0c4 0 7-3 7-7-4 0-7 3-7 7ZM5 22h14"/>',
    library:
        '<path d="M12 5C9 3 5 3 2 4v16c3-1 7-1 10 1 3-2 7-2 10-1V4c-3-1-7-1-10 1Zm0 0v16"/>',
    restaurant:
        '<path d="M7 2v20M4 2v6a3 3 0 0 0 6 0V2m7 20V2c-2 1-3 4-3 8h3"/>',
    culture:
        '<path d="M3 21h18M5 21V10m14 11V10M9 21V10m6 11V10M2 10h20L12 3 2 10Z"/>',
    fastfood:
        '<path d="M4 11a8 6 0 0 1 16 0H4Zm-1 3h18M5 17h14a3 3 0 0 1-3 3H8a3 3 0 0 1-3-3Z"/>',
    nature: '<path d="M12 2 6 10h3l-4 6h14l-4-6h3l-6-8Zm0 14v6"/>',
    // Places for sport, and what a park has (the mock's connected/places.js).
    sports: '<path d="M6 7v10M3 9v6m15-8v10m3-8v6M6 12h12"/>',
    pitch: '<path d="M3 6h18v12H3zM12 6v12"/><circle cx="12" cy="12" r="2.5"/>',
    table_tennis: '<path d="M3 10h18v3H3zM6 13v6m12-6v6M12 10V7"/>',
    boules: '<circle cx="8" cy="15" r="4"/><circle cx="17" cy="15" r="3"/><circle cx="13" cy="7" r="1.5"/>',
    dog_park:
        '<path d="M6 15c0-3 3-5 6-5s6 2 6 5-3 4-6 4-6-1-6-4Z"/><circle cx="7" cy="8" r="1.5"/><circle cx="12" cy="6" r="1.5"/><circle cx="17" cy="8" r="1.5"/>',
    outdoor: '<path d="M3 10a9 6 0 0 1 18 0H3Zm9 0v11M7 21h10M8 15h8"/>',
    leaf: '<path d="M5 19c0-8 5-14 15-14 0 10-6 15-14 15H5v-1Zm0 0 8-8"/>',
    wifi: '<path d="M2 9a15 15 0 0 1 20 0M5 12.5a10 10 0 0 1 14 0M8.5 16a5 5 0 0 1 7 0"/><circle cx="12" cy="19.5" r="1"/>',
    wheelchair:
        '<circle cx="12" cy="4" r="2"/><path d="M12 7v6h5l2 6M12 10H8m3 3a5 5 0 1 0 4 8"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    pin: '<path d="M12 22s7-6 7-12a7 7 0 0 0-14 0c0 6 7 12 7 12Z"/><circle cx="12" cy="10" r="2.5"/>',
    bookmark: '<path d="M6 3h12v18l-6-4-6 4V3Z"/>',
    route: '<circle cx="6" cy="19" r="2"/><circle cx="18" cy="5" r="2"/><path d="M12 19h4.5a3.5 3.5 0 0 0 0-7h-8a3.5 3.5 0 0 1 0-7H12"/>',
    share: '<path d="M12 3v12M8 7l4-4 4 4M5 13v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6"/>',
    been: '<circle cx="12" cy="12" r="8.5"/><path d="M8 12.5l2.7 2.7L16 9.5"/>',
} as const;

export type PlaceShape = keyof typeof SHAPES;

export const placeShape = (name: string): string =>
    SHAPES[name as PlaceShape] ?? SHAPES.pin;

/** A place icon (the pin for a kind without its own). */
export function PlaceIcon({ name }: { name: string }) {
    return (
        <svg
            className="place-icon"
            viewBox="0 0 24 24"
            aria-hidden="true"
            dangerouslySetInnerHTML={{ __html: placeShape(name) }}
        />
    );
}
