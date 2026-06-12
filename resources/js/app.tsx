import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { Component, StrictMode } from 'react';
import type { ErrorInfo, ReactNode } from 'react';
import { createRoot } from 'react-dom/client';
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

        // Load Sentry async — out of the critical render path
        import('@/lib/sentry').then((m) => m.initSentry());
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
