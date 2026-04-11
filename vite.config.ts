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
            manifest: {
                name: 'Expadu — Your City Guide',
                short_name: 'Expadu',
                description: 'Smart city companion for expats in Germany',
                theme_color: '#1A4CD4',
                background_color: '#F6F5F1',
                display: 'standalone',
                scope: '/',
                start_url: '/dashboard',
                icons: [
                    {
                        src: '/favicon.svg',
                        sizes: 'any',
                        type: 'image/svg+xml',
                    },
                    {
                        src: '/apple-touch-icon.png',
                        sizes: '180x180',
                        type: 'image/png',
                    },
                ],
            },
            injectManifest: {
                injectionPoint: undefined,
            },
        }),
        compression({ algorithm: 'gzip', include: /\.(js|css|svg|json)$/ }),
        compression({ algorithm: 'brotliCompress', include: /\.(js|css|svg|json)$/ }),
    ],
    build: {
        rollupOptions: {
            output: {
                manualChunks(id) {
                    if (id.includes('node_modules')) {
                        if (id.includes('react') || id.includes('react-dom') || id.includes('scheduler')) {
                            return 'vendor-react';
                        }
                        if (id.includes('maplibre-gl')) {
                            return 'vendor-maplibre';
                        }
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
