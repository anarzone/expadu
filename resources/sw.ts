/// <reference lib="webworker" />

import { ExpirationPlugin } from 'workbox-expiration';
import { registerRoute } from 'workbox-routing';
import {
    CacheFirst,
    NetworkFirst,
    StaleWhileRevalidate,
} from 'workbox-strategies';

declare let self: ServiceWorkerGlobalScope;

// Take control as soon as installed
self.addEventListener('install', () => {
    self.skipWaiting();
});

// ── Runtime Caching ──

// Build assets (hashed filenames) — cache forever
registerRoute(
    ({ url }) => url.pathname.startsWith('/build/assets/'),
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
    ({ url }) => url.pathname.startsWith('/fonts/'),
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

// Map tiles — show cached instantly, update in background
registerRoute(
    ({ url }) =>
        url.hostname.includes('tile') || url.pathname.includes('/tiles/'),
    new StaleWhileRevalidate({
        cacheName: 'map-tiles',
        plugins: [
            new ExpirationPlugin({
                maxEntries: 500,
                maxAgeSeconds: 7 * 24 * 60 * 60,
            }),
        ],
    }),
);

// Inertia page requests — network first with offline fallback
// Exclude partial/deferred requests (live transit data, weather, etc.)
registerRoute(
    ({ request }) =>
        request.headers.get('X-Inertia') === 'true' &&
        !request.headers.get('X-Inertia-Partial-Component'),
    new NetworkFirst({
        cacheName: 'inertia-pages',
        plugins: [
            new ExpirationPlugin({
                maxEntries: 30,
                maxAgeSeconds: 24 * 60 * 60,
            }),
        ],
    }),
);

// API requests — network first
registerRoute(
    ({ url }) => url.pathname.startsWith('/api/'),
    new NetworkFirst({
        cacheName: 'api',
        plugins: [
            new ExpirationPlugin({ maxEntries: 50, maxAgeSeconds: 60 * 60 }),
        ],
    }),
);

// ── Push Notification Handler ──
self.addEventListener('push', (event) => {
    let data: Record<string, unknown> = {};

    try {
        data = event.data?.json() ?? {};
    } catch {
        // Malformed push payload — show generic notification
    }

    const title = data.title ?? 'Expadu';
    const options: NotificationOptions = {
        body: data.body ?? '',
        icon: data.icon ?? '/favicon.svg',
        badge: '/favicon.svg',
        tag: data.tag ?? 'expadu-notification',
        data: {
            url: data.data?.url ?? data.url ?? '/dashboard',
        },
        actions: data.actions ?? [],
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

// ── Notification Click Handler ──
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = event.notification.data?.url ?? '/dashboard';

    event.waitUntil(
        self.clients
            .matchAll({ type: 'window', includeUncontrolled: true })
            .then((clientList) => {
                for (const client of clientList) {
                    if (
                        client.url.includes(self.location.origin) &&
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

// ── Activate: claim clients + clear old caches ──
self.addEventListener('activate', (event) => {
    event.waitUntil(
        self.clients
            .claim()
            .then(() =>
                caches
                    .keys()
                    .then((keys) =>
                        Promise.all(
                            keys
                                .filter(
                                    (key) =>
                                        key.startsWith('workbox-precache') ||
                                        key.includes('precache'),
                                )
                                .map((key) => caches.delete(key)),
                        ),
                    ),
            ),
    );
});
