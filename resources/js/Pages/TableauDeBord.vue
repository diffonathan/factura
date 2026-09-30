<script setup>
import { Head, Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import Application from '../Layout/Application.vue'
import { montant, montantCourt, date, moisCourt } from '../format'

defineOptions({ layout: Application })

const props = defineProps({
    exercice: Number,
    chiffres: Object,
    impayes: Array,
    aRelancer: { type: Array, default: () => [] },
    recents: Array,
    mensuel: Array,
})

/**
 * Le graphique est dessiné à la main, en SVG.
 *
 * Douze barres et un axe ne valent pas 90 ko de bibliothèque de graphiques :
 * c'est l'essentiel du poids de la page pour une figure qu'on écrit en vingt
 * lignes. Et le SVG reste lisible à l'impression et par un lecteur d'écran.
 */
const maximum = computed(() => {
    const valeurs = props.mensuel.map((m) => Number.parseFloat(m.ht))

    // Jamais zéro : une division par zéro donnerait des hauteurs NaN et un
    // graphique vide, sans message d'erreur.
    return Math.max(...valeurs, 1)
})

const cartes = computed(() => [
    {
        titre: 'Facturé', suffixe: 'HT', valeur: props.chiffres.facture_ht,
        note: `Exercice ${props.exercice}, avoirs déduits`, ton: 'neutre',
    },
    {
        titre: 'Encaissé', suffixe: 'TTC', valeur: props.chiffres.encaisse,
        note: 'Ce qui est réellement rentré', ton: 'caisse',
    },
    {
        titre: 'Reste dû', suffixe: 'TTC', valeur: props.chiffres.reste_du,
        note: 'Factures émises non soldées', ton: 'echeance',
    },
    {
        titre: 'En retard', suffixe: 'TTC', valeur: props.chiffres.en_retard,
        note: 'Échéance dépassée', ton: 'impaye',
    },
])

const COULEUR = {
    neutre: 'text-encre',
    caisse: 'text-caisse',
    echeance: 'text-echeance',
    impaye: 'text-impaye',
}

const NIVEAUX = { 1: 'Rappel courtois', 2: 'Relance ferme', 3: 'Mise en demeure' }
</script>

<template>
    <Head title="Tableau de bord" />

    <div class="flex items-baseline justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Tableau de bord</h1>
            <p class="mt-1 text-sm text-encre-douce">
                Exercice {{ exercice }} · {{ chiffres.clients }} clients ·
                TVA collectée <span class="nombre">{{ montant(chiffres.tva_collectee) }}</span> MAD
            </p>
        </div>
        <Link
            v-if="chiffres.brouillons > 0"
            href="/documents?statut=BROUILLON"
            class="rounded-lg border border-trait px-3 py-1.5 text-[13px] font-medium text-encre-douce transition-colors hover:border-encre-douce hover:text-encre"
        >
            {{ chiffres.brouillons }} brouillon{{ chiffres.brouillons > 1 ? 's' : '' }} en attente
        </Link>
    </div>

    <section class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <article
            v-for="carte in cartes"
            :key="carte.titre"
            class="rounded-2xl border border-trait bg-white p-5"
        >
            <p class="text-[11px] font-semibold uppercase tracking-wider text-encre-douce">
                {{ carte.titre }}
            </p>
            <p class="nombre mt-2 text-[1.65rem] font-semibold leading-none" :class="COULEUR[carte.ton]">
                {{ montantCourt(carte.valeur) }}
                <span class="text-sm font-medium text-encre-douce">MAD {{ carte.suffixe }}</span>
            </p>
            <p class="mt-2.5 text-xs text-encre-douce">{{ carte.note }}</p>
        </article>
    </section>

    <div class="mt-6 grid gap-6 lg:grid-cols-5">
        <section class="rounded-2xl border border-trait bg-white p-6 lg:col-span-3">
            <h2 class="text-sm font-semibold">Facturé par mois</h2>
            <p class="mt-1 text-xs text-encre-douce">Douze derniers mois, hors taxes, avoirs déduits.</p>

            <svg viewBox="0 0 600 190" class="mt-6 w-full" role="img"
                 aria-label="Montant facturé hors taxes des douze derniers mois">
                <g v-for="(mois, index) in mensuel" :key="mois.mois">
                    <rect
                        :x="index * 50 + 10"
                        :y="160 - (Number.parseFloat(mois.ht) / maximum) * 140"
                        width="30"
                        :height="Math.max((Number.parseFloat(mois.ht) / maximum) * 140, 1)"
                        rx="4"
                        :fill="Number.parseFloat(mois.ht) > 0 ? 'var(--color-caisse)' : 'var(--color-trait)'"
                        :opacity="index === mensuel.length - 1 ? 1 : 0.75"
                    />
                    <text
                        :x="index * 50 + 25"
                        y="180"
                        text-anchor="middle"
                        class="fill-encre-douce text-[10px]"
                    >{{ moisCourt(mois.mois) }}</text>
                </g>
            </svg>
        </section>

        <section class="rounded-2xl border border-trait bg-white p-6 lg:col-span-2">
            <h2 class="text-sm font-semibold">À relancer</h2>
            <p class="mt-1 text-xs text-encre-douce">
                Ce que le balayage de 8 h mettrait en file s'il tournait maintenant.
            </p>

            <p v-if="aRelancer.length === 0" class="mt-6 text-sm text-encre-douce">
                Rien à relancer. Toutes les factures échues sont soldées.
            </p>

            <ul v-else class="mt-4 space-y-2">
                <li v-for="ligne in aRelancer" :key="ligne.id">
                    <Link
                        :href="`/documents/${ligne.id}`"
                        class="flex items-center gap-3 rounded-xl border border-trait px-3 py-2.5 transition-colors hover:border-encre-douce"
                    >
                        <span
                            class="grid h-7 w-7 shrink-0 place-items-center rounded-lg text-[12px] font-bold"
                            :class="ligne.niveau === 3 ? 'bg-impaye-clair text-impaye' : 'bg-echeance-clair text-echeance'"
                        >{{ ligne.niveau }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[13px] font-medium">{{ ligne.client }}</span>
                            <span class="block text-[11px] text-encre-douce">
                                {{ NIVEAUX[ligne.niveau] }} · {{ ligne.retard }} j de retard
                            </span>
                        </span>
                        <span class="nombre shrink-0 text-[13px] font-semibold">
                            {{ montantCourt(ligne.reste) }}
                        </span>
                    </Link>
                </li>
            </ul>
        </section>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <section class="rounded-2xl border border-trait bg-white p-6">
            <h2 class="text-sm font-semibold">Plus gros impayés</h2>

            <p v-if="impayes.length === 0" class="mt-6 text-sm text-encre-douce">Aucun impayé.</p>

            <table v-else class="mt-4 w-full text-sm">
                <tbody>
                    <tr v-for="facture in impayes" :key="facture.id" class="border-b border-trait last:border-0">
                        <td class="py-2.5">
                            <Link :href="`/documents/${facture.id}`" class="nombre text-[13px] font-medium hover:text-caisse">
                                {{ facture.reference }}
                            </Link>
                            <span class="block text-xs text-encre-douce">{{ facture.client }}</span>
                        </td>
                        <td class="py-2.5 text-right text-xs"
                            :class="facture.retard > 0 ? 'text-impaye' : 'text-encre-douce'">
                            {{ facture.retard > 0 ? `${facture.retard} j` : date(facture.echeance) }}
                        </td>
                        <td class="nombre py-2.5 pl-4 text-right text-[13px] font-semibold">
                            {{ montant(facture.reste) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section class="rounded-2xl border border-trait bg-white p-6">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold">Derniers documents</h2>
                <Link href="/documents" class="text-[13px] font-medium text-caisse hover:underline">Tout voir</Link>
            </div>

            <table class="mt-4 w-full text-sm">
                <tbody>
                    <tr v-for="document in recents" :key="document.id" class="border-b border-trait last:border-0">
                        <td class="py-2.5">
                            <Link :href="`/documents/${document.id}`" class="nombre text-[13px] font-medium hover:text-caisse">
                                {{ document.reference ?? 'Brouillon' }}
                            </Link>
                            <span class="block truncate text-xs text-encre-douce">{{ document.client }}</span>
                        </td>
                        <td class="py-2.5 text-right text-xs text-encre-douce">{{ date(document.date) }}</td>
                        <td class="nombre py-2.5 pl-4 text-right text-[13px] font-semibold">
                            {{ montant(document.ttc) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
</template>
