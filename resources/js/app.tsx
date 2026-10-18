import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { Component, StrictMode } from 'react';
import type { ErrorInfo, ReactNode } from 'react';
import { createRoot, hydrateRoot } from 'react-dom/client';
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
        // Reuse server markup so a slow startup does not replace an already
        // visible form or discard input. SSR can be unavailable, so keep the
        // empty-root fallback and the duplicate-module guard.
        type RootEl = HTMLElement & {
            __inertiaRoot?: ReturnType<typeof createRoot>;
        };
        const rootEl = el as RootEl;
        const app = (
            <StrictMode>
                <ErrorBoundary>
                    <TooltipProvider delayDuration={0}>
                        <App {...props} />
                    </TooltipProvider>
                </ErrorBoundary>
            </StrictMode>
        );

        if (rootEl.__inertiaRoot) {
            rootEl.__inertiaRoot.render(app);
        } else if (el.hasChildNodes()) {
            rootEl.__inertiaRoot = hydrateRoot(el, app);
        } else {
            rootEl.__inertiaRoot = createRoot(el);
            rootEl.__inertiaRoot.render(app);
        }

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

// After a deploy the hashed chunk files an open tab points at are gone, so the
// next lazy import (a page, the map) 404s. Reload once to pick up the new build.
// The timestamp keeps a chunk that is genuinely broken from reloading forever:
// within the window (or without usable storage) the original error is thrown
// as usual instead.
const CHUNK_RELOAD_KEY = 'expadu:chunk-reload-at';
const CHUNK_RELOAD_WINDOW_MS = 30_000;

function reloadOnceForStaleChunk(): boolean {
    try {
        const now = Date.now();
        const last = Number(sessionStorage.getItem(CHUNK_RELOAD_KEY));

        if (last > 0 && now - last < CHUNK_RELOAD_WINDOW_MS) {
            return false;
        }

        sessionStorage.setItem(CHUNK_RELOAD_KEY, String(now));
    } catch {
        return false;
    }

    window.location.reload();

    return true;
}

if (typeof window !== 'undefined') {
    window.addEventListener('vite:preloadError', (event) => {
        if (reloadOnceForStaleChunk()) {
            event.preventDefault();
        }
    });
}

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
        // The toast is held in a module store, so calling this even during
        // Inertia's error re-render (which remounts <FlashToast>) is safe —
        // the freshly-mounted toast reads the latest message.
        showToast(first);
    }
});

// A response Inertia cannot use. An ad blocker is ONE cause, and it used to be
// the only one we named — so an expired session or a rejected request told the
// user to go hunting for a browser extension. Both arrive here identically:
// no `x-inertia` header, because Laravel's 419/403 responses are not Inertia
// responses. Say which it was.
router.on('invalid', (event) => {
    event.preventDefault();

    const status = event.detail.response?.status;

    showToast(
        status === 419
            ? 'Your session expired while this page was open. Reload the page and try again — nothing was saved.'
            : status === 403
              ? 'That action was refused. The page is probably out of date — reload it and try again.'
              : status !== undefined && status >= 500
                ? 'The server hit an error. Nothing was saved — try again in a moment.'
                : 'Something blocked the response — an ad blocker or browser extension may be interfering. Try turning it off for this site, or reload.',
    );
});

// The request itself failed (network, blocked).
router.on('exception', () => {
    showToast(
        "Couldn't reach the server. Check your connection and try again.",
    );
});
