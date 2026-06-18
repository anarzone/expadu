import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { Component, StrictMode } from 'react';
import type { ErrorInfo, ReactNode } from 'react';
import { createRoot } from 'react-dom/client';
import { showToast } from '@/components/flash-toast';
import { TooltipProvider } from '@/components/ui/tooltip';
import '../css/app.css';
import { initializeTheme } from '@/hooks/use-appearance';

/**
 * Lightweight error boundary that renders immediately (no Sentry dependency).
 * Once Sentry loads async, errors will also be captured there.
 */
class ErrorBoundary extends Component<
    { children: ReactNode },
    { hasError: boolean }
> {
    state = { hasError: false };

    static getDerivedStateFromError() {
        return { hasError: true };
    }

    componentDidCatch(error: Error, info: ErrorInfo) {
        // Forward to Sentry if it has loaded by now
        import('@sentry/react')
            .then((Sentry) =>
                Sentry.captureException(error, {
                    extra: { componentStack: info.componentStack },
                }),
            )
            .catch(() => {});
    }

    render() {
        if (this.state.hasError) {
            return (
                <div style={{ padding: 40, textAlign: 'center' }}>
                    <h2>Something went wrong</h2>
                    <p style={{ color: '#6B6860', marginTop: 8 }}>
                        An unexpected error occurred. Please refresh the page.
                    </p>
                    <button
                        onClick={() => window.location.reload()}
                        style={{
                            marginTop: 16,
                            padding: '8px 20px',
                            background: '#1A4CD4',
                            color: 'white',
                            border: 'none',
                            borderRadius: 8,
                            cursor: 'pointer',
                        }}
                    >
                        Refresh
                    </button>
                </div>
            );
        }

        return this.props.children;
    }
}

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.tsx`,
            import.meta.glob('./pages/**/*.tsx'),
        ),
    setup({ el, App, props }) {
        // Guard against double createRoot on the same element (SW replays /
        // duplicate module evaluation log a React warning otherwise).
        type RootEl = HTMLElement & {
            __inertiaRoot?: ReturnType<typeof createRoot>;
        };
        const rootEl = el as RootEl;
        const root = (rootEl.__inertiaRoot ??= createRoot(el));

        root.render(
            <StrictMode>
                <ErrorBoundary>
                    <TooltipProvider delayDuration={0}>
                        <App {...props} />
                    </TooltipProvider>
                </ErrorBoundary>
            </StrictMode>,
        );

        // Load Sentry async — out of the critical render path. Guarded so a
        // blocked chunk (ad blockers block "sentry-*.js") is a silent no-op,
        // never an unhandled rejection.
        import('@/lib/sentry').then((m) => m.initSentry()).catch(() => {});
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();

// Never let a request fail silently. Validation errors already render inline,
// but an ad blocker / extension can hide an element or garble a response — so
// also surface them through an independent toast channel.
const AUTH_PATH = /^\/(login|register|forgot-password|reset-password)/;

// Validation errors on the auth screens — mirror the first message as a toast
// so it shows even if the inline field error is hidden.
router.on('error', (event) => {
    if (!AUTH_PATH.test(window.location.pathname)) {
        return;
    }

    const first = Object.values(event.detail.errors)[0];

    if (typeof first === 'string') {
        const message = first;
        // Defer past Inertia's error re-render (which remounts the toast) so
        // the dispatch isn't dropped.
        setTimeout(() => showToast(message), 0);
    }
});

// A non-Inertia response (commonly an ad blocker / extension interfering).
router.on('invalid', (event) => {
    event.preventDefault();
    showToast(
        'Something blocked the response — an ad blocker or browser extension may be interfering. Try turning it off for this site, or reload.',
    );
});

// The request itself failed (network, blocked).
router.on('exception', () => {
    showToast(
        "Couldn't reach the server. Check your connection and try again.",
    );
});
