import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            // Um ponto de entrada só: o pages.js importa o CSS do Bootstrap e o resources/css/pages.css.
            input: ['resources/js/pages.js'],
            refresh: true,
        }),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
