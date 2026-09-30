<script setup>
/**
 * La marque de Factura — un reçu dentelé, trois lignes, la dernière plus
 * courte parce que c'est le total.
 *
 * Écrit en composant plutôt qu'en `<img src="/brand/marque.svg">` pour une
 * raison précise : en SVG inline, le signe hérite de la taille du texte
 * environnant, se colore par CSS si besoin, et surtout n'ajoute pas une
 * requête réseau sur l'écran de connexion — le premier qu'un visiteur voit,
 * et celui où chaque requête se remarque.
 *
 * La source de vérité reste `brand/marque.svg` : ce fichier-ci en est la
 * transcription, et les deux doivent bouger ensemble.
 */
defineProps({
    taille: { type: Number, default: 32 },
})
</script>

<template>
    <svg
        :width="taille"
        :height="taille"
        viewBox="0 0 64 64"
        role="img"
        aria-label="Factura"
        class="shrink-0"
    >
        <defs>
            <!-- L'identifiant du dégradé est unique par instance : deux marques
                 sur la même page partageraient sinon le même `id`, et la
                 seconde hériterait silencieusement du dégradé de la première. -->
            <linearGradient :id="`or-${taille}`" x1="0" y1="0" x2="1" y2="1" gradientTransform="rotate(-15 .5 .5)">
                <stop offset="0" stop-color="#e4bd5c" />
                <stop offset="1" stop-color="#a67e12" />
            </linearGradient>
        </defs>

        <rect width="64" height="64" rx="15" :fill="`url(#or-${taille})`" />

        <path
            fill="#14100a"
            d="M18 12H46Q49 12 49 15V43L44.75 47L40.5 43L36.25 47L32 43L27.75 47L23.5 43L19.25 47L15 43V15Q15 12 18 12Z"
        />

        <rect x="20" y="19.5" width="24" height="3.4" rx="1.7" fill="#e4bd5c" />
        <rect x="20" y="27" width="24" height="3.4" rx="1.7" fill="#e4bd5c" />
        <rect x="20" y="34.5" width="13" height="3.4" rx="1.7" fill="#e4bd5c" />
    </svg>
</template>
