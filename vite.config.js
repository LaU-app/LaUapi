import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css', 
                'resources/css/menu-mobile.css',
                'resources/css/style.css',
                'resources/css/responsive.css',
                'resources/js/app.js',
                'resources/js/script.js',
                // Lo carga el layout del panel (layouts/app-su); sin él, un build limpio rompe el panel.
                'resources/js/script-lau.js',
                'resources/js/jquery-3.6.0.min.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
});
