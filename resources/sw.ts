/// <reference lib="webworker" />

declare let self: ServiceWorkerGlobalScope;

// Take control as soon as installed
self.addEventListener('install', () => {
    self.skipWaiting();
});

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
