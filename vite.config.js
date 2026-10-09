import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            fonts: [
                bunny('Barlow', {
                    weights: [400, 500, 600, 700],
                }),
                bunny('Barlow Condensed', {
                    weights: [600, 700, 800],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        origin: `http://localhost:${process.env.VITE_PORT ?? 5173}`,
        cors: true,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
