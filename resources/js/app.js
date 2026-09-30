import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';

createInertiaApp({
    title: (titre) => (titre ? `${titre} — Factura` : 'Factura'),

    // import.meta.glob avec eager:false : chaque page devient un morceau
    // chargé à la demande. Sans cela, ouvrir l'écran de connexion
    // téléchargerait aussi l'éditeur de facture et le tableau de bord.
    resolve: (nom) => {
        const pages = import.meta.glob('./Pages/**/*.vue');
        const page = pages[`./Pages/${nom}.vue`];
        if (!page) {
            throw new Error(`Page Inertia introuvable : ${nom}`);
        }
        return page();
    },

    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },

    progress: {
        // La barre de progression reprend le vert de la marque.
        color: '#d4a017',
    },
});
