import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    // Sans cela, un <img src="/images/logo.svg"> dans un
                    // composant Vue est réécrit par Vite en import, et
                    // l'image sert depuis le serveur de développement au lieu
                    // du dossier public de Laravel. Les deux moteurs doivent
                    // s'accorder sur qui possède les chemins absolus.
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
