<script setup>
import { Head, Link, useForm, router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import Application from '../../Layout/Application.vue'
import { montant, date, dateLongue, tonStatut, CLASSES_TON } from '../../format'

defineOptions({ layout: Application })

const props = defineProps({
    document: Object,
    client: Object,
    emetteur: Object,
    lignes: Array,
    paiements: Array,
    relances: Array,
    prochaineRelance: { type: Number, default: null },
    modesPaiement: Array,
})

const encaissementOuvert = ref(false)

const encaissement = useForm({
    montant: props.document.reste,
    mode: 'VIREMENT',
    date_paiement: new Date().toISOString().slice(0, 10),
    reference: '',
})

const encaissable = computed(
    () => !props.document.est_brouillon
        && ['FACTURE', 'AVOIR'].includes(props.document.type)
        && Number.parseFloat(props.document.reste) > 0,
)

function agir(chemin) {
    router.post(`/documents/${props.document.id}/${chemin}`, {}, { preserveScroll: true })
}

function encaisser() {
    encaissement.post(`/documents/${props.document.id}/encaisser`, {
        preserveScroll: true,
        onSuccess: () => {
            encaissementOuvert.value = false
            encaissement.reset('reference')
        },
    })
}

const TITRE_TAUX = { '0.00': 'Exonéré', '7.00': '7 %', '10.00': '10 %', '14.00': '14 %', '20.00': '20 %' }
</script>

<template>
    <Head :title="document.reference ?? 'Brouillon'" />

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <Link href="/documents" class="text-[13px] font-medium text-texte-doux hover:text-texte">
                ← Tous les documents
            </Link>
            <h1 class="nombre mt-2 text-2xl font-semibold tracking-tight">
                {{ document.reference ?? `${document.type_libelle} — brouillon` }}
            </h1>
            <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-texte-doux">
                <span
                    class="inline-flex rounded-md px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide"
                    :class="CLASSES_TON[tonStatut(document.statut, document.retard)]"
                >{{ document.statut_libelle }}</span>
                <span>{{ document.objet }}</span>
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            <button
                v-if="document.est_brouillon"
                type="button"
                class="bouton-accent rounded-lg px-4 py-2 text-[13px]"
                @click="agir('emettre')"
            >
                Émettre ce {{ document.type_libelle.toLowerCase() }}
            </button>

            <button
                v-if="document.type === 'DEVIS' && !document.est_brouillon && document.derives.length === 0"
                type="button"
                class="bouton-discret rounded-lg px-4 py-2 text-[13px] font-semibold"
                @click="agir('convertir')"
            >
                Convertir en facture
            </button>

            <button
                v-if="document.type === 'FACTURE' && !document.est_brouillon && document.statut !== 'ANNULE'"
                type="button"
                class="bouton-discret rounded-lg px-4 py-2 text-[13px] font-semibold"
                @click="agir('avoir')"
            >
                Établir un avoir
            </button>

            <button
                v-if="encaissable"
                type="button"
                class="bouton-discret rounded-lg px-4 py-2 text-[13px] font-semibold"
                @click="encaissementOuvert = !encaissementOuvert"
            >
                Enregistrer un règlement
            </button>
        </div>
    </div>

    <!-- Le formulaire d'encaissement, replié tant qu'on n'en a pas besoin. -->
    <form
        v-if="encaissementOuvert"
        class="mt-5 grid gap-4 verre rounded-2xl p-5 sm:grid-cols-4"
        @submit.prevent="encaisser"
    >
        <label class="block">
            <span class="text-[13px] font-medium">Montant</span>
            <input
                v-model="encaissement.montant"
                type="number" step="0.01" min="0.01" :max="document.reste" required
                class="champ nombre mt-1.5 w-full rounded-lg px-3 py-2 text-sm"
            >
            <span class="mt-1 block text-xs text-texte-doux">
                Reste dû : <span class="nombre">{{ montant(document.reste, document.devise) }}</span>
            </span>
        </label>

        <label class="block">
            <span class="text-[13px] font-medium">Mode</span>
            <select
                v-model="encaissement.mode"
                class="champ mt-1.5 w-full rounded-lg px-3 py-2 text-sm"
            >
                <option v-for="mode in modesPaiement" :key="mode.valeur" :value="mode.valeur">
                    {{ mode.libelle }}
                </option>
            </select>
        </label>

        <label class="block">
            <span class="text-[13px] font-medium">Date</span>
            <input
                v-model="encaissement.date_paiement" type="date" required
                class="champ mt-1.5 w-full rounded-lg px-3 py-2 text-sm"
            >
        </label>

        <label class="block">
            <span class="text-[13px] font-medium">Référence</span>
            <input
                v-model="encaissement.reference" type="text" placeholder="N° de chèque, virement…"
                class="champ mt-1.5 w-full rounded-lg px-3 py-2 text-sm"
            >
        </label>

        <div class="sm:col-span-4">
            <button
                type="submit" :disabled="encaissement.processing"
                class="bouton-accent rounded-lg px-4 py-2 text-[13px] disabled:opacity-50"
            >
                Enregistrer
            </button>
        </div>
    </form>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <!-- Le document tel qu'il sera imprimé. -->
        <article class="verre rounded-2xl p-8 lg:col-span-2">
            <header class="flex justify-between gap-8 border-b border-bordure pb-6">
                <div>
                    <p class="text-base font-semibold">{{ emetteur.raison_sociale }}</p>
                    <p class="mt-1 text-[13px] leading-relaxed text-texte-doux">
                        {{ emetteur.adresse }}<br>
                        {{ emetteur.ville }}<br>
                        {{ emetteur.telephone }}
                    </p>
                </div>
                <div class="text-right">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-texte-doux">
                        {{ document.type_libelle }}
                    </p>
                    <p class="nombre text-lg font-semibold">{{ document.reference ?? 'Brouillon' }}</p>
                    <p class="mt-2 text-[13px] text-texte-doux">
                        Date : {{ dateLongue(document.date) }}<br>
                        <template v-if="document.echeance">
                            {{ document.type === 'DEVIS' ? 'Valable jusqu’au' : 'Échéance' }} :
                            {{ dateLongue(document.echeance) }}
                        </template>
                    </p>
                </div>
            </header>

            <div class="flex justify-between gap-8 border-b border-bordure py-5">
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-texte-doux">Client</p>
                    <p class="mt-1 text-sm font-medium">{{ client.nom }}</p>
                    <p class="text-[13px] leading-relaxed text-texte-doux">
                        <template v-if="client.adresse">{{ client.adresse }}<br></template>
                        {{ client.ville }}
                        <template v-if="client.ice"><br>ICE : <span class="nombre">{{ client.ice }}</span></template>
                        <template v-if="client.identifiant_fiscal"><br>IF : <span class="nombre">{{ client.identifiant_fiscal }}</span></template>
                    </p>
                </div>
                <div v-if="document.origine" class="text-right text-[13px] text-texte-doux">
                    <p class="text-[11px] font-semibold uppercase tracking-wider">Fait suite à</p>
                    <Link :href="`/documents/${document.origine.id}`" class="nombre font-medium text-accent hover:underline">
                        {{ document.origine.reference }}
                    </Link>
                </div>
            </div>

            <table class="mt-5 w-full text-sm">
                <thead>
                    <tr class="border-b border-bordure text-left text-[11px] uppercase tracking-wider text-texte-doux">
                        <th class="pb-2 font-semibold">Désignation</th>
                        <th class="pb-2 text-right font-semibold">Qté</th>
                        <th class="pb-2 text-right font-semibold">P.U. HT</th>
                        <th class="pb-2 text-right font-semibold">Remise</th>
                        <th class="pb-2 text-right font-semibold">TVA</th>
                        <th class="pb-2 text-right font-semibold">Total HT</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="ligne in lignes" :key="ligne.id" class="border-b border-bordure last:border-0">
                        <td class="py-2.5">
                            {{ ligne.designation }}
                            <span v-if="ligne.unite !== 'unité'" class="block text-xs text-texte-doux">
                                en {{ ligne.unite }}
                            </span>
                        </td>
                        <td class="nombre py-2.5 text-right">{{ Number.parseFloat(ligne.quantite) }}</td>
                        <td class="nombre py-2.5 text-right">{{ montant(ligne.prix) }}</td>
                        <td class="nombre py-2.5 text-right text-texte-doux">
                            {{ Number.parseFloat(ligne.remise) > 0 ? `${Number.parseFloat(ligne.remise)} %` : '—' }}
                        </td>
                        <td class="py-2.5 text-right text-texte-doux">{{ TITRE_TAUX[ligne.taux] ?? ligne.taux }}</td>
                        <td class="nombre py-2.5 text-right font-medium">{{ montant(ligne.ht) }}</td>
                    </tr>
                    <tr v-if="lignes.length === 0">
                        <td colspan="6" class="py-8 text-center text-sm text-texte-doux">
                            Aucune ligne. Ce document ne peut pas être émis tel quel.
                        </td>
                    </tr>
                </tbody>
            </table>

            <div class="mt-6 flex justify-end">
                <dl class="w-64 space-y-1.5 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-texte-doux">Total HT</dt>
                        <dd class="nombre font-medium">{{ montant(document.ht) }}</dd>
                    </div>
                    <!-- La TVA ventilée par taux : mention obligatoire dès qu'une
                         facture mélange plusieurs taux. -->
                    <div v-for="tranche in document.ventilation" :key="tranche.taux" class="flex justify-between">
                        <dt class="text-texte-doux">
                            TVA {{ tranche.taux }} % sur <span class="nombre">{{ montant(tranche.base) }}</span>
                        </dt>
                        <dd class="nombre">{{ montant(tranche.tva) }}</dd>
                    </div>
                    <div class="flex justify-between border-t border-bordure pt-2 text-base">
                        <dt class="font-semibold">Total TTC</dt>
                        <dd class="nombre font-semibold">{{ montant(document.ttc) }} {{ document.devise }}</dd>
                    </div>
                    <div v-if="Number.parseFloat(document.paye) > 0" class="flex justify-between">
                        <dt class="text-texte-doux">Déjà réglé</dt>
                        <dd class="nombre text-accent">− {{ montant(document.paye) }}</dd>
                    </div>
                    <div v-if="Number.parseFloat(document.reste) > 0" class="flex justify-between font-semibold"
                         :class="document.retard > 0 ? 'text-perte' : ''">
                        <dt>Reste dû</dt>
                        <dd class="nombre">{{ montant(document.reste) }}</dd>
                    </div>
                </dl>
            </div>

            <footer class="mt-8 border-t border-bordure pt-5 text-xs leading-relaxed text-texte-doux">
                <p v-if="document.conditions" class="mb-3">{{ document.conditions }}</p>
                <p v-if="emetteur.rib" class="mb-3">
                    Virement — {{ emetteur.banque }}, RIB <span class="nombre">{{ emetteur.rib }}</span>
                </p>
                <p>{{ emetteur.mentions.join(' · ') }}</p>
            </footer>
        </article>

        <aside class="space-y-6">
            <section class="verre rounded-2xl p-5">
                <h2 class="text-sm font-semibold">Règlements</h2>

                <p v-if="paiements.length === 0" class="mt-3 text-[13px] text-texte-doux">
                    Aucun règlement enregistré.
                </p>

                <ul v-else class="mt-3 space-y-2.5">
                    <li v-for="paiement in paiements" :key="paiement.id"
                        class="flex items-baseline justify-between gap-3 border-b border-bordure pb-2.5 last:border-0 last:pb-0">
                        <span class="min-w-0">
                            <span class="nombre block text-[13px] font-medium">{{ montant(paiement.montant) }}</span>
                            <span class="block truncate text-xs text-texte-doux">
                                {{ paiement.mode }}<template v-if="paiement.reference"> · {{ paiement.reference }}</template>
                            </span>
                        </span>
                        <span class="nombre shrink-0 text-xs text-texte-doux">{{ date(paiement.date) }}</span>
                    </li>
                </ul>
            </section>

            <section v-if="relances.length > 0 || prochaineRelance" class="verre rounded-2xl p-5">
                <h2 class="text-sm font-semibold">Relances</h2>

                <ul class="mt-3 space-y-2.5">
                    <li v-for="relance in relances" :key="relance.niveau"
                        class="flex items-baseline justify-between gap-3">
                        <span>
                            <span class="text-[13px] font-medium">{{ relance.libelle }}</span>
                            <span v-if="relance.statut === 'ECHEC'" class="block text-xs text-perte">
                                Envoi en échec — à reprendre à la main
                            </span>
                        </span>
                        <span class="nombre shrink-0 text-xs text-texte-doux">
                            {{ relance.le ? date(relance.le.slice(0, 10)) : '' }}
                        </span>
                    </li>
                </ul>

                <p v-if="prochaineRelance" class="mt-3 rounded-lg bg-white/5 px-3 py-2 text-xs text-texte-doux">
                    Niveau {{ prochaineRelance }} au prochain balayage.
                </p>
            </section>

            <section v-if="document.derives.length > 0" class="verre rounded-2xl p-5">
                <h2 class="text-sm font-semibold">Documents liés</h2>

                <ul class="mt-3 space-y-2">
                    <li v-for="derive in document.derives" :key="derive.id">
                        <Link :href="`/documents/${derive.id}`"
                              class="flex items-baseline justify-between gap-3 hover:text-accent">
                            <span class="nombre text-[13px] font-medium">{{ derive.reference ?? 'Brouillon' }}</span>
                            <span class="nombre text-xs text-texte-doux">{{ montant(derive.ttc) }}</span>
                        </Link>
                    </li>
                </ul>
            </section>

            <section v-if="document.notes_internes" class="rounded-2xl border border-bordure bg-surface p-5">
                <h2 class="text-sm font-semibold">Note interne</h2>
                <p class="mt-2 text-[13px] leading-relaxed text-texte-doux">{{ document.notes_internes }}</p>
                <p class="mt-2 text-xs text-texte-doux">Non imprimée sur le document.</p>
            </section>
        </aside>
    </div>
</template>
