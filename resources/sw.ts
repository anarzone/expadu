/// <reference lib="webworker" />

declare let self: ServiceWorkerGlobalScope;

// Take control immediately
self.skipWaiting();
self.clients.claim();

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

// ── Clear old Workbox caches from previous versions ──
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
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
    );
});
