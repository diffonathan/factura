<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import Application from '../../Layout/Application.vue'
import { montant, date, tonStatut, CLASSES_TON } from '../../format'

defineOptions({ layout: Application })

const props = defineProps({
    documents: Object,
    filtres: Object,
    types: Array,
    statuts: Array,
})

const recherche = ref(props.filtres.recherche ?? '')

/**
 * La recherche part 350 ms après la dernière frappe.
 *
 * Sans cette attente, « Pharmacie » déclencherait neuf requêtes dont huit
 * périmées — et, sur une connexion lente, la réponse de « Pharm » pourrait
 * arriver après celle de « Pharmacie » et réafficher les mauvais résultats.
 */
let minuterie = null

watch(recherche, (valeur) => {
    clearTimeout(minuterie)
    minuterie = setTimeout(() => filtrer({ recherche: valeur }), 350)
})

function filtrer(modifications) {
    router.get('/documents', { ...props.filtres, ...modifications }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })
}

const LIBELLES_TYPE = { DEVIS: 'Devis', FACTURE: 'Facture', AVOIR: 'Avoir' }
</script>

<template>
    <Head title="Documents" />

    <div class="flex items-baseline justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Documents</h1>
            <p class="mt-1 text-sm text-texte-doux">
                {{ documents.total }} document{{ documents.total > 1 ? 's' : '' }} ·
                devis, factures et avoirs
            </p>
        </div>

        <Link href="/documents/nouveau" class="bouton-accent rounded-lg px-4 py-2 text-[13px]">
            Nouveau document
        </Link>
    </div>

    <div class="mt-6 flex flex-wrap items-center gap-3">
        <input
            v-model="recherche"
            type="search"
            placeholder="Référence, objet ou client…"
            class="champ w-64 rounded-lg px-3 py-2 text-sm"
        >

        <select
            :value="filtres.type"
            @change="filtrer({ type: $event.target.value })"
        >
            <option value="">Tous les types</option>
            <option v-for="type in types" :key="type.valeur" :value="type.valeur">{{ type.libelle }}</option>
        </select>

        <select
            :value="filtres.statut"
            @change="filtrer({ statut: $event.target.value })"
        >
            <option value="">Tous les états</option>
            <option v-for="statut in statuts" :key="statut.valeur" :value="statut.valeur">{{ statut.libelle }}</option>
        </select>

        <button
            v-if="filtres.type || filtres.statut || filtres.recherche"
            type="button"
            class="text-[13px] font-medium text-texte-doux hover:text-texte"
            @click="recherche = ''; filtrer({ type: '', statut: '', recherche: '' })"
        >
            Effacer les filtres
        </button>
    </div>

    <section class="mt-5 overflow-hidden verre rounded-2xl">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-bordure text-left text-[11px] uppercase tracking-wider text-texte-doux">
                    <th class="px-5 py-3 font-semibold">Référence</th>
                    <th class="px-5 py-3 font-semibold">Client</th>
                    <th class="px-5 py-3 font-semibold">Objet</th>
                    <th class="px-5 py-3 font-semibold">Date</th>
                    <th class="px-5 py-3 text-right font-semibold">Total TTC</th>
                    <th class="px-5 py-3 text-right font-semibold">Reste dû</th>
                    <th class="px-5 py-3 font-semibold">État</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="document in documents.data"
                    :key="document.id"
                    class="border-b border-bordure last:border-0 transition-colors hover:bg-white/[0.025]"
                >
                    <td class="px-5 py-3">
                        <Link :href="`/documents/${document.id}`" class="nombre font-medium hover:text-accent">
                            {{ document.reference ?? '—' }}
                        </Link>
                        <span class="block text-[11px] uppercase tracking-wide text-texte-doux">
                            {{ LIBELLES_TYPE[document.type] }}
                        </span>
                    </td>
                    <td class="px-5 py-3">
                        {{ document.client }}
                        <span class="block text-xs text-texte-doux">{{ document.ville }}</span>
                    </td>
                    <td class="max-w-[16rem] truncate px-5 py-3 text-texte-doux">{{ document.objet }}</td>
                    <td class="nombre px-5 py-3 text-[13px] text-texte-doux">{{ date(document.date) }}</td>
                    <td class="nombre px-5 py-3 text-right font-semibold">{{ montant(document.ttc) }}</td>
                    <td class="nombre px-5 py-3 text-right"
                        :class="Number.parseFloat(document.reste) > 0 ? 'font-semibold' : 'text-texte-doux'">
                        {{ montant(document.reste) }}
                    </td>
                    <td class="px-5 py-3">
                        <span
                            class="inline-flex rounded-md px-2 py-1 text-[11px] font-semibold uppercase tracking-wide"
                            :class="CLASSES_TON[tonStatut(document.statut, document.retard)]"
                        >
                            {{ document.retard > 0 ? `${document.retard} j de retard` : document.statut.toLowerCase() }}
                        </span>
                    </td>
                </tr>

                <tr v-if="documents.data.length === 0">
                    <td colspan="7" class="px-5 py-12 text-center text-sm text-texte-doux">
                        Aucun document ne correspond à ces filtres.
                    </td>
                </tr>
            </tbody>
        </table>
    </section>

    <nav v-if="documents.last_page > 1" class="mt-5 flex items-center justify-center gap-1">
        <Link
            v-for="lien in documents.links"
            :key="lien.label"
            :href="lien.url ?? '#'"
            class="rounded-lg px-3 py-1.5 text-[13px] font-medium transition-colors"
            :class="[
                lien.active ? 'bg-surface-haute text-texte' : 'text-texte-doux hover:bg-surface',
                lien.url ? '' : 'pointer-events-none opacity-40',
            ]"
            v-html="lien.label"
        />
    </nav>
</template>
