/**
 * Expadu uses MapLibre with OpenFreeMap's public vector styles. OpenFreeMap is
 * free for commercial use, requires no account or API key, and includes the
 * required OpenStreetMap attribution in its styles.
 *
 * Keeping the style URL remote is deliberate: the former self-hosted PMTiles
 * file was gitignored and therefore disappeared from Docker deployments,
 * leaving both Places and journey maps blank. A map must not depend on an
 * untracked local build artifact.
 */

const OPENFREEMAP_STYLES = {
    light: 'https://tiles.openfreemap.org/styles/positron',
    dark: 'https://tiles.openfreemap.org/styles/dark',
} as const;

/** Cologne, roughly the Dom — the fallback view when there's nothing to frame. */
export const COLOGNE_CENTER: [number, number] = [6.957, 50.938];

/**
 * Lazily load MapLibre and its stylesheet so map code stays outside the initial
 * Places/Events bundle until a user actually opens a map.
 */
export async function loadBasemap() {
    const maplibregl = await import('maplibre-gl');
    await import('maplibre-gl/dist/maplibre-gl.css');

    const style = (dark: boolean): string =>
        dark ? OPENFREEMAP_STYLES.dark : OPENFREEMAP_STYLES.light;

    return { maplibregl, style };
}

/** Everything {@link loadBasemap} resolves with. */
export type Basemap = Awaited<ReturnType<typeof loadBasemap>>;
