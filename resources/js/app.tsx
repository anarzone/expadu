import './lib/sentry';
import { createInertiaApp } from '@inertiajs/react';
import * as Sentry from '@sentry/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { TooltipProvider } from '@/components/ui/tooltip';
import '../css/app.css';
import { initializeTheme } from '@/hooks/use-appearance';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.tsx`,
            import.meta.glob('./pages/**/*.tsx'),
        ),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(
            <StrictMode>
                <Sentry.ErrorBoundary
                    fallback={
                        <div style={{ padding: 40, textAlign: 'center' }}>
                            <h2>Something went wrong</h2>
                            <p style={{ color: '#6B6860', marginTop: 8 }}>
                                An unexpected error occurred. Please refresh the
                                page.
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
                    }
                >
                    <TooltipProvider delayDuration={0}>
                        <App {...props} />
                    </TooltipProvider>
                </Sentry.ErrorBoundary>
            </StrictMode>,
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
