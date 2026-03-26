import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';
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
            registerType: 'autoUpdate',
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
                    { src: '/favicon.svg', sizes: 'any', type: 'image/svg+xml' },
                    { src: '/apple-touch-icon.png', sizes: '180x180', type: 'image/png' },
                ],
            },
            workbox: {
                globPatterns: ['**/*.{js,css,html,ico,png,svg,woff2}'],
                runtimeCaching: [
                    {
                        urlPattern: /^https:\/\/api\.brightsky\.dev/,
                        handler: 'StaleWhileRevalidate',
                        options: {
                            cacheName: 'weather-cache',
                            expiration: { maxEntries: 10, maxAgeSeconds: 900 },
                        },
                    },
                    {
                        urlPattern: /^https:\/\/tiles\.openfreemap\.org/,
                        handler: 'CacheFirst',
                        options: {
                            cacheName: 'map-tiles',
                            expiration: { maxEntries: 500, maxAgeSeconds: 86400 * 7 },
                        },
                    },
                ],
            },
        }),
    ],
});
