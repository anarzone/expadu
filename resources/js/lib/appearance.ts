/**
 * Appearance (owner, 1 Nov 2026: dark mode, the Espresso palette): System,
 * Light or Dark, kept per device in the `appearance` cookie so the server
 * can paint the right one before any script runs (app.blade.php). Dark is
 * `html.dark`: app.css turns every colour token over under it, so pages
 * only ever read the tokens. System follows the device as it changes.
 */

export type Appearance = 'system' | 'light' | 'dark';

const COOKIE = 'appearance';
const DARK_QUERY = '(prefers-color-scheme: dark)';
/** The page background in each look, for the browser's own chrome (app.css --color-bg-canvas). */
const THEME_COLOR = { light: '#faf6ee', dark: '#12100d' } as const;
/** Fired on window whenever the look changes, for maps and canvases that draw their own colours. */
export const APPEARANCE_EVENT = 'expadu:appearance';

const isAppearance = (value: unknown): value is Appearance =>
    value === 'system' || value === 'light' || value === 'dark';

export function getAppearance(): Appearance {
    if (typeof document === 'undefined') {
        return 'system';
    }

    const value = document.cookie.match(/(?:^|; )appearance=([a-z]+)/)?.[1];

    return isAppearance(value) ? value : 'system';
}

/** Whether the page is dark right now. */
export function isDark(): boolean {
    return (
        typeof document !== 'undefined' &&
        document.documentElement.classList.contains('dark')
    );
}

function systemDark(): boolean {
    return window.matchMedia(DARK_QUERY).matches;
}

function apply(appearance: Appearance) {
    const dark =
        appearance === 'dark' || (appearance === 'system' && systemDark());
    const root = document.documentElement;
    const changed = root.classList.contains('dark') !== dark;

    root.classList.toggle('dark', dark);
    // The server may have drawn one per device setting (System); now one look holds.
    document.querySelectorAll('meta[name="theme-color"]').forEach((meta) => {
        meta.removeAttribute('media');
        meta.setAttribute('content', THEME_COLOR[dark ? 'dark' : 'light']);
    });

    if (changed) {
        window.dispatchEvent(new CustomEvent(APPEARANCE_EVENT));
    }
}

export function setAppearance(appearance: Appearance) {
    // A year, the whole site; read by the server, so neither encrypted nor HttpOnly.
    document.cookie = `${COOKIE}=${appearance}; path=/; max-age=31536000; SameSite=Lax`;
    apply(appearance);
}

let started = false;

/** Once, at boot: keep the look in step with the device while it's System. */
export function initializeAppearance() {
    if (started || typeof window === 'undefined') {
        return;
    }

    started = true;
    apply(getAppearance());
    window
        .matchMedia(DARK_QUERY)
        .addEventListener('change', () => apply(getAppearance()));
}

/** Subscribe to the look changing (useSyncExternalStore, maps). */
export function onAppearanceChange(notify: () => void): () => void {
    window.addEventListener(APPEARANCE_EVENT, notify);

    return () => window.removeEventListener(APPEARANCE_EVENT, notify);
}
