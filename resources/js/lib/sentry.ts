/**
 * Lazy Sentry initialization.
 * Called after app mounts to keep @sentry/react out of the critical path.
 */
export async function initSentry(): Promise<void> {
    const dsn = import.meta.env.VITE_SENTRY_DSN_PUBLIC;

    if (!dsn) {
        return;
    }

    const Sentry = await import('@sentry/react');

    Sentry.init({
        dsn,
        // Prefer an explicit env (staging|production, injected at build) over
        // Vite's MODE, which is always "production" for any built bundle.
        environment:
            import.meta.env.VITE_SENTRY_ENVIRONMENT ?? import.meta.env.MODE,
        release: import.meta.env.VITE_SENTRY_RELEASE || undefined,
        // Never attach the browser's IP / cookies. The backend attributes
        // errors by user id, which is enough to correlate the two sides.
        sendDefaultPii: false,
        tracesSampleRate: 0.1,
        replaysSessionSampleRate: 0,
        replaysOnErrorSampleRate: 1.0,
        ignoreErrors: [
            'ResizeObserver loop',
            'Non-Error promise rejection',
            'AbortError',
        ],
        beforeSend(event) {
            // GPS coordinates must never leave in a URL — strip query strings
            // off the request and any navigation breadcrumb.
            if (event.request?.url) {
                event.request.url = event.request.url.split('?')[0];
            }

            event.breadcrumbs = event.breadcrumbs?.map((b) => {
                if (typeof b.data?.to === 'string') {
                    b.data.to = (b.data.to as string).split('?')[0];
                }

                return b;
            });

            return event;
        },
    });
}
