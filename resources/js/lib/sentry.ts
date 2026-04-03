import * as Sentry from '@sentry/react';

const dsn = import.meta.env.VITE_SENTRY_DSN_PUBLIC;

if (dsn) {
    Sentry.init({
        dsn,
        environment: import.meta.env.MODE,
        tracesSampleRate: 0.1,
        replaysSessionSampleRate: 0,
        replaysOnErrorSampleRate: 1.0,
        ignoreErrors: [
            'ResizeObserver loop',
            'Non-Error promise rejection',
            'AbortError',
        ],
    });
}

export { Sentry };
