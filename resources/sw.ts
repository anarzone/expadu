/// <reference lib="webworker" />

import { precacheAndRoute, cleanupOutdatedCaches } from 'workbox-precaching';
import { clientsClaim } from 'workbox-core';
import { registerRoute } from 'workbox-routing';
import { StaleWhileRevalidate, CacheFirst } from 'workbox-strategies';
import { ExpirationPlugin } from 'workbox-expiration';

declare let self: ServiceWorkerGlobalScope;

// Take control immediately
self.skipWaiting();
clientsClaim();

// Precache built assets (injected by VitePWA)
precacheAndRoute(self.__WB_MANIFEST);
cleanupOutdatedCaches();

// Runtime caching: Weather API
registerRoute(
    /^https:\/\/api\.brightsky\.dev/,
    new StaleWhileRevalidate({
        cacheName: 'weather-cache',
        plugins: [new ExpirationPlugin({ maxEntries: 10, maxAgeSeconds: 900 })],
    }),
);

// Runtime caching: Map tiles
registerRoute(
    /^https:\/\/tiles\.openfreemap\.org/,
    new CacheFirst({
        cacheName: 'map-tiles',
        plugins: [
            new ExpirationPlugin({ maxEntries: 500, maxAgeSeconds: 86400 * 7 }),
        ],
    }),
);

// ── Push Notification Handler ──
self.addEventListener('push', (event) => {
    const data = event.data?.json() ?? {};

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

    // Focus existing window or open new one
    event.waitUntil(
        self.clients
            .matchAll({ type: 'window', includeUncontrolled: true })
            .then((clientList) => {
                // Try to focus an existing window on the same origin
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
                // No existing window — open a new one
                return self.clients.openWindow(url);
            }),
    );
});
