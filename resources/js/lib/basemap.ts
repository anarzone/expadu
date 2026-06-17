import type { StyleSpecification } from 'maplibre-gl';
import type { Theme } from 'protomaps-themes-base';

/**
 * Self-hosted vector basemap — one Protomaps `.pmtiles` file (the Cologne
 * cutout, z0–15) read via HTTP range requests. No tile server, no Google,
 * no third-party tile host. The file is large, so it's gitignored and
 * transferred to the server like the routing data; nginx serves it as a
 * plain static asset with range support.
 *
 * `maplibre-gl`, `pmtiles` and the theme are loaded lazily by {@link loadBasemap}
 * so the Places bundle stays slim until a map is actually opened.
 */

/** Served from the web root (public/maps/cologne.pmtiles). */
export const PMTILES_URL = '/maps/cologne.pmtiles';

/** Glyphs (label fonts) + POI sprite — tiny static assets from Protomaps' CDN. */
const GLYPHS_URL =
    'https://protomaps.github.io/basemaps-assets/fonts/{fontstack}/{range}.pbf';
const spriteUrl = (dark: boolean) =>
    `https://protomaps.github.io/basemaps-assets/sprites/v4/${dark ? 'dark' : 'light'}`;

/** Cologne, roughly the Dom — the fallback view when there's nothing to frame. */
export const COLOGNE_CENTER: [number, number] = [6.957, 50.938];

/**
 * Expadu's map palette — mirrors the `--map-*` CSS tokens (light + dark) so
 * the basemap reads as paper land, soft-green parks and a muted Rhine rather
 * than a generic OSM vector map.
 */
const PALETTE = {
    light: {
        bg: '#EAE7DF',
        park: '#DCE7CF',
        wood: '#D3E0C2',
        water: '#BBD3E8',
        road: '#FBFAF7',
        border: '#E2DFD6',
        building: '#E4E0D6',
        boundary: '#C9C5BA',
        text: '#6B6860',
        city: '#18170F',
        halo: '#F6F5F1',
    },
    dark: {
        bg: '#232219',
        park: '#28301F',
        wood: '#2C3522',
        water: '#233649',
        road: '#2E2D23',
        border: '#3A3930',
        building: '#2A2920',
        boundary: '#3A3930',
        text: '#AAA89F',
        city: '#F6F5F1',
        halo: '#14130D',
    },
} as const;

/** Tint a stock Protomaps theme with the Expadu map palette. */
function expaduTheme(base: Theme, dark: boolean): Theme {
    const m = dark ? PALETTE.dark : PALETTE.light;

    return {
        ...base,
        background: m.bg,
        earth: m.bg,
        glacier: m.bg,
        sand: m.bg,
        beach: m.bg,
        water: m.water,
        waterway_label: m.water,
        park_a: m.park,
        park_b: m.park,
        scrub_a: m.park,
        scrub_b: m.park,
        zoo: m.park,
        wood_a: m.wood,
        wood_b: m.wood,
        buildings: m.building,
        pedestrian: m.road,
        pier: m.bg,
        other: m.road,
        minor_service: m.road,
        minor_a: m.road,
        minor_b: m.road,
        link: m.road,
        major: m.road,
        highway: m.road,
        minor_service_casing: m.border,
        minor_casing: m.border,
        link_casing: m.border,
        major_casing_late: m.border,
        highway_casing_late: m.border,
        major_casing_early: m.border,
        highway_casing_early: m.border,
        railway: m.boundary,
        boundaries: m.boundary,
        roads_label_minor: m.text,
        roads_label_major: m.text,
        roads_label_minor_halo: m.halo,
        roads_label_major_halo: m.halo,
        city_label: m.city,
        city_label_halo: m.halo,
        state_label: m.text,
        state_label_halo: m.halo,
        country_label: m.text,
        subplace_label: m.text,
        subplace_label_halo: m.halo,
        address_label: m.text,
        address_label_halo: m.halo,
        ocean_label: m.text,
        peak_label: m.text,
    };
}

let protocolRegistered = false;

/**
 * Lazily load MapLibre + the pmtiles protocol + the theme, register the
 * `pmtiles://` protocol once, and return a style builder. Safe to call more
 * than once — the protocol is only added on the first call.
 */
export async function loadBasemap() {
    const [maplibregl, { Protocol }, themes] = await Promise.all([
        import('maplibre-gl'),
        import('pmtiles'),
        import('protomaps-themes-base'),
        // Ships with the chunk; required for canvas + control sizing.
        import('maplibre-gl/dist/maplibre-gl.css'),
    ]);

    if (!protocolRegistered) {
        const protocol = new Protocol();
        maplibregl.addProtocol('pmtiles', protocol.tile);
        protocolRegistered = true;
    }

    const style = (dark: boolean): StyleSpecification => ({
        version: 8,
        glyphs: GLYPHS_URL,
        sprite: spriteUrl(dark),
        sources: {
            protomaps: {
                type: 'vector',
                url: `pmtiles://${PMTILES_URL}`,
                attribution:
                    '<a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noreferrer">&copy; OpenStreetMap</a>',
            },
        },
        layers: themes.layers(
            'protomaps',
            expaduTheme(themes.namedTheme(dark ? 'dark' : 'light'), dark),
            { lang: 'en' },
        ),
    });

    return { maplibregl, style };
}

/** Everything {@link loadBasemap} resolves with (the MapLibre namespace + style builder). */
export type Basemap = Awaited<ReturnType<typeof loadBasemap>>;
