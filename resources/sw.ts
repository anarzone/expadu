/// <reference lib="webworker" />

import { ExpirationPlugin } from 'workbox-expiration';
import { registerRoute } from 'workbox-routing';
import { CacheFirst, StaleWhileRevalidate } from 'workbox-strategies';

declare let self: ServiceWorkerGlobalScope;

// Generic page shown only when a navigation fails because the network is
// unreachable. It is static and carries no account data, so caching it is safe.
const OFFLINE_URL = '/offline.html';
// Bump the suffix whenever public/offline.html changes so installed workers
// fetch the new copy (a new worker version re-adds it on install).
const OFFLINE_CACHE = 'offline-fallback-v1';

// Take control as soon as installed
self.addEventListener('install', (event) => {
    self.skipWaiting();
    event.waitUntil(cacheOfflinePage());
});

// ── Runtime Caching ──

// Build assets (hashed filenames) — cache forever
registerRoute(
    ({ url }) =>
        url.origin === self.location.origin &&
        url.pathname.startsWith('/build/assets/'),
    new CacheFirst({
        cacheName: 'build-assets',
        plugins: [
            new ExpirationPlugin({
                maxEntries: 100,
                maxAgeSeconds: 365 * 24 * 60 * 60,
            }),
        ],
    }),
);

// Self-hosted fonts — cache forever
registerRoute(
    ({ url }) =>
        url.origin === self.location.origin &&
        url.pathname.startsWith('/fonts/'),
    new CacheFirst({
        cacheName: 'fonts',
        plugins: [
            new ExpirationPlugin({
                maxEntries: 20,
                maxAgeSeconds: 365 * 24 * 60 * 60,
            }),
        ],
    }),
);

// Map tiles, glyphs and sprite images (OpenFreeMap). The tile URL carries the
// planet build version, so a given URL never changes — serve it from cache
// without revalidating. Only 200 responses are stored.
registerRoute(
    ({ url }) =>
        url.origin !== self.location.origin &&
        /\.(?:pbf|mvt|png|webp)$/.test(url.pathname) &&
        (url.hostname.includes('tile') || url.pathname.includes('/tiles/')),
    new CacheFirst({
        cacheName: 'map-tiles',
        plugins: [
            new ExpirationPlugin({
                maxEntries: 500,
                maxAgeSeconds: 30 * 24 * 60 * 60,
                purgeOnQuotaError: true,
            }),
        ],
    }),
);

// Map style JSON, the TileJSON that names the current tile version, and the
// sprite index. These are unversioned and do change, so show the cached copy
// instantly (the map can start offline) and refresh it in the background.
registerRoute(
    ({ url }) =>
        url.hostname === 'tiles.openfreemap.org' &&
        (url.pathname.startsWith('/styles/') ||
            url.pathname === '/planet' ||
            /^\/sprites\/.+\.json$/.test(url.pathname)),
    new StaleWhileRevalidate({
        cacheName: 'map-styles',
        plugins: [
            new ExpirationPlugin({
                maxEntries: 20,
                maxAgeSeconds: 30 * 24 * 60 * 60,
                purgeOnQuotaError: true,
            }),
        ],
    }),
);

// Private pages and APIs always go to the network. Cached account data and
// stale departures must never be replayed as current information after logout.
// Only the navigation handler below touches page requests, and it never caches
// them.

// ── Navigations ──
// Page loads go to the network, using the navigation-preload response so a cold
// worker no longer delays the request. If the network itself is unreachable the
// generic offline page is served instead. HTTP error responses pass through
// untouched, and only plain GET navigations are handled: Inertia XHRs (header
// X-Inertia, not navigations), API calls and form posts are never intercepted.
self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (
        request.mode !== 'navigate' ||
        request.method !== 'GET' ||
        request.headers.has('X-Inertia')
    ) {
        return;
    }

    event.respondWith(respondToNavigation(event));
});

async function respondToNavigation(event: FetchEvent): Promise<Response> {
    try {
        const preloaded = await event.preloadResponse;

        return preloaded ?? (await fetch(event.request));
    } catch {
        const offline = await caches.match(OFFLINE_URL, {
            cacheName: OFFLINE_CACHE,
        });

        return offline ?? Response.error();
    }
}

async function cacheOfflinePage(): Promise<void> {
    try {
        const cache = await caches.open(OFFLINE_CACHE);

        await cache.add(new Request(OFFLINE_URL, { cache: 'reload' }));
    } catch {
        // A missing fallback must never block installing a new worker, which
        // would also block the activate step that purges private caches.
    }
}

function enableNavigationPreload(): Promise<void> {
    // Not every browser supports navigation preload, so feature-detect it.
    return Promise.resolve(
        self.registration?.navigationPreload?.enable(),
    ).catch(() => {});
}

// ── Push Notification Handler ──
self.addEventListener('push', (event) => {
    let data: Record<string, unknown> = {};

    try {
        data = event.data?.json() ?? {};
    } catch {
        // Malformed push payload — show generic notification
    }

    const title = typeof data.title === 'string' ? data.title : 'Expadu';
    const options: NotificationOptions = {
        body: typeof data.body === 'string' ? data.body : '',
        icon: '/favicon.svg',
        badge: '/favicon.svg',
        tag: typeof data.tag === 'string' ? data.tag : 'expadu-notification',
        data: {
            url: notificationUrl(data),
        },
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

// ── Notification Click Handler ──
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = notificationUrl(event.notification.data ?? {});

    event.waitUntil(
        self.clients
            .matchAll({ type: 'window', includeUncontrolled: true })
            .then((clientList) => {
                for (const client of clientList) {
                    if (
                        new URL(client.url).origin === self.location.origin &&
                        'focus' in client
                    ) {
                        client.focus();
                        client.navigate(url);

                        return;
                    }
                }

                return self.clients.openWindow(url);
            }),
    );
});

function notificationUrl(data: Record<string, unknown>): string {
    const nested =
        data.data && typeof data.data === 'object'
            ? (data.data as Record<string, unknown>).url
            : undefined;
    const candidate = typeof nested === 'string' ? nested : data.url;

    try {
        const url = new URL(
            typeof candidate === 'string' ? candidate : '/dashboard',
            self.location.origin,
        );

        return url.origin === self.location.origin
            ? url.href
            : new URL('/dashboard', self.location.origin).href;
    } catch {
        return new URL('/dashboard', self.location.origin).href;
    }
}

// Purge private caches written by older workers (and superseded offline pages)
// before taking control, and switch on navigation preload.
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) =>
                Promise.all(
                    keys
                        .filter(
                            (key) =>
                                key.includes('precache') ||
                                key === 'inertia-pages' ||
                                key === 'api' ||
                                (key.startsWith('offline-fallback-') &&
                                    key !== OFFLINE_CACHE),
                        )
                        .map((key) => caches.delete(key)),
                ),
            )
            .then(enableNavigationPreload)
            .then(() => self.clients.claim()),
    );
});
