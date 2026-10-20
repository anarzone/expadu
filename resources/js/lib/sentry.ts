import {
    browserTracingIntegration,
    captureException,
    init,
} from '@sentry/react';
import type { ErrorInfo } from 'react';

/**
 * Lazy Sentry. app.tsx imports this module only after the app mounts, so
 * @sentry/react stays out of the critical path — and everything Sentry goes
 * through here, so there is one Sentry chunk. Named imports keep Replay,
 * Feedback and the rest of the SDK out of it.
 */
export function initSentry(): void {
    const dsn = import.meta.env.VITE_SENTRY_DSN_PUBLIC;

    if (!dsn) {
        return;
    }

    init({
        dsn,
        // Prefer an explicit env (staging|production, injected at build) over
        // Vite's MODE, which is always "production" for any built bundle.
        environment:
            import.meta.env.VITE_SENTRY_ENVIRONMENT ?? import.meta.env.MODE,
        release: import.meta.env.VITE_SENTRY_RELEASE || undefined,
        // Never attach the browser's IP / cookies. The backend attributes
        // errors by user id, which is enough to correlate the two sides.
        sendDefaultPii: false,
        // Page-load and navigation timings for 10% of sessions. The sample
        // rate was set before, but without this integration nothing traced.
        integrations: [browserTracingIntegration()],
        tracesSampleRate: 0.1,
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

/** Report a render error caught by the app's error boundary. No-op until init ran. */
export function captureRenderError(error: Error, info: ErrorInfo): void {
    captureException(error, { extra: { componentStack: info.componentStack } });
}
