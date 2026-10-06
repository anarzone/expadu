/// <reference lib="webworker" />

import { ExpirationPlugin } from 'workbox-expiration';
import { registerRoute } from 'workbox-routing';
import { CacheFirst, StaleWhileRevalidate } from 'workbox-strategies';

declare let self: ServiceWorkerGlobalScope;

// Take control as soon as installed
self.addEventListener('install', () => {
    self.skipWaiting();
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

// Map tiles — show cached instantly, update in background
registerRoute(
    ({ url }) =>
        url.origin !== self.location.origin &&
        /\.(?:pbf|mvt|png|webp)$/.test(url.pathname) &&
        (url.hostname.includes('tile') || url.pathname.includes('/tiles/')),
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

// Private pages and APIs always go to the network. Cached account data and
// stale departures must never be replayed as current information after logout.

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

// Purge private caches written by older workers before taking control.
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
                                key === 'api',
                        )
                        .map((key) => caches.delete(key)),
                ),
            )
            .then(() => self.clients.claim()),
    );
});
