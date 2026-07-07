import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';
import { compression } from 'vite-plugin-compression2';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            ssr: 'resources/js/ssr.tsx',
            refresh: true,
        }),
        react({
            babel: {
                plugins: ['babel-plugin-react-compiler'],
            },
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
        VitePWA({
            strategies: 'injectManifest',
            srcDir: 'resources',
            filename: 'sw.ts',
            registerType: 'autoUpdate',
            buildBase: '/',
            scope: '/',
            // The linked manifest is the static /site.webmanifest; this generated
            // one is kept in sync so the build never ships a contradictory copy.
            manifest: {
                name: 'Expadu',
                short_name: 'Expadu',
                description: 'Your city. Your guide.',
                lang: 'en',
                categories: ['travel', 'utilities', 'navigation'],
                theme_color: '#faf6ee',
                background_color: '#faf6ee',
                display: 'standalone',
                orientation: 'portrait',
                scope: '/',
                start_url: '/',
                icons: [
                    {
                        src: '/favicon.svg',
                        sizes: 'any',
                        type: 'image/svg+xml',
                        purpose: 'any',
                    },
                    {
                        src: '/favicon-96x96.png',
                        sizes: '96x96',
                        type: 'image/png',
                    },
                    {
                        src: '/icon-192.png',
                        sizes: '192x192',
                        type: 'image/png',
                        purpose: 'any',
                    },
                    {
                        src: '/icon-512.png',
                        sizes: '512x512',
                        type: 'image/png',
                        purpose: 'any',
                    },
                    {
                        src: '/icon-maskable-192.png',
                        sizes: '192x192',
                        type: 'image/png',
                        purpose: 'maskable',
                    },
                    {
                        src: '/icon-maskable-512.png',
                        sizes: '512x512',
                        type: 'image/png',
                        purpose: 'maskable',
                    },
                ],
            },
            injectManifest: {
                injectionPoint: undefined,
            },
        }),
        compression({ algorithm: 'gzip', include: /\.(js|css|svg|json)$/ }),
        compression({
            algorithm: 'brotliCompress',
            include: /\.(js|css|svg|json)$/,
        }),
    ],
    ssr: {
        // Bundle all dependencies into the SSR output so production
        // doesn't need node_modules at runtime
        noExternal: true,
    },
    build: {
        rollupOptions: {
            output: {
                manualChunks(id) {
                    if (id.includes('node_modules')) {
                        if (
                            id.includes('react') ||
                            id.includes('react-dom') ||
                            id.includes('scheduler')
                        ) {
                            return 'vendor-react';
                        }
                        // maplibre-gl is ONLY loaded via dynamic import
                        // (MiniMap) — it gets its own async chunk naturally.
                        // Forcing it into a manual chunk produced a broken
                        // module ("Export 'maplibre_gl_exports' is not
                        // defined") that silently killed every mini-map in
                        // production builds.
                        if (id.includes('@radix-ui')) {
                            return 'vendor-radix';
                        }
                        if (id.includes('@tabler/icons')) {
                            return 'vendor-icons';
                        }
                    }
                },
            },
        },
    },
});
