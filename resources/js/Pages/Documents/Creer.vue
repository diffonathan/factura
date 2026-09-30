<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'
import Application from '../../Layout/Application.vue'
import { montant } from '../../format'

defineOptions({ layout: Application })

const props = defineProps({
    clients: Array,
    types: Array,
    tauxTva: Array,
    aujourdhui: String,
})

function ligneVide() {
    return {
        designation: '',
        unite: 'unité',
        quantite: 1,
        prix_unitaire_ht: '',
        remise_pct: 0,
        taux_tva: 20,
    }
}

const formulaire = useForm({
    type: 'FACTURE',
    client_id: props.clients[0]?.id ?? null,
    date_emission: props.aujourdhui,
    date_echeance: null,
    objet: '',
    conditions: '',
    notes_internes: '',
    lignes: [ligneVide()],
    emettre: false,
})

const clientChoisi = computed(
    () => props.clients.find((c) => c.id === formulaire.client_id) ?? null,
)

const estDevis = computed(() => formulaire.type === 'DEVIS')

/**
 * L'aperçu des totaux, calculé dans le navigateur.
 *
 * Il reproduit EXACTEMENT ce que fait PostgreSQL : arrondi à deux décimales
 * ligne par ligne, puis somme. Arrondir seulement à la fin donnerait parfois
 * un centime d'écart avec le document enregistré, et c'est le genre de détail
 * qui fait douter de tout le reste.
 *
 * Ce n'est qu'un aperçu : la vérité reste la base, qui recalculera ses totaux
 * à partir des lignes. Le navigateur ne fait ici qu'éviter d'enregistrer pour
 * voir combien ça fait.
 */
const totaux = computed(() => {
    let ht = 0
    let tva = 0

    for (const l of formulaire.lignes) {
        const quantite = Number.parseFloat(l.quantite) || 0
        const prix = Number.parseFloat(l.prix_unitaire_ht) || 0
        const remise = Number.parseFloat(l.remise_pct) || 0
        const taux = Number.parseFloat(l.taux_tva) || 0

        const base = arrondi(quantite * prix * (1 - remise / 100))
        ht += base
        tva += arrondi((base * taux) / 100)
    }

    return { ht: arrondi(ht), tva: arrondi(tva), ttc: arrondi(ht + tva) }
})

// `Math.round(x * 100) / 100` échoue sur des valeurs comme 1.005 à cause de la
// représentation binaire. Le détour par la notation exponentielle contourne le
// problème, et donne le même résultat que `round(numeric, 2)` en base.
function arrondi(valeur) {
    return Number(Math.round(Number(`${valeur}e2`)) + 'e-2')
}

/** L'échéance proposée suit le délai du client, comme le fera le serveur. */
const echeanceSuggeree = computed(() => {
    if (!formulaire.date_emission) return null

    const jours = estDevis.value ? 30 : (clientChoisi.value?.delai ?? 30)
    const d = new Date(`${formulaire.date_emission}T00:00:00`)
    d.setDate(d.getDate() + jours)

    return d.toISOString().slice(0, 10)
})

function ajouterLigne() {
    formulaire.lignes.push(ligneVide())
}

function retirerLigne(i) {
    // Jamais zéro ligne : un document vide ne peut pas être émis, et un
    // tableau vide donne un écran qui semble cassé.
    if (formulaire.lignes.length === 1) {
        formulaire.lignes.splice(0, 1, ligneVide())
        return
    }
    formulaire.lignes.splice(i, 1)
}

function dupliquerLigne(i) {
    formulaire.lignes.splice(i + 1, 0, { ...formulaire.lignes[i] })
}

function enregistrer(emettre) {
    formulaire.emettre = emettre
    formulaire.date_echeance = formulaire.date_echeance || echeanceSuggeree.value
    formulaire.post('/documents', { preserveScroll: true })
}

/** Le message d'erreur d'une ligne, quel que soit le champ fautif. */
function erreurLigne(i) {
    const prefixe = `lignes.${i}.`

    return Object.entries(formulaire.errors)
        .filter(([cle]) => cle.startsWith(prefixe))
        .map(([, message]) => message)[0] ?? null
}
</script>

<template>
    <Head title="Nouveau document" />

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <Link href="/documents" class="text-[13px] font-medium text-texte-doux hover:text-texte">
                ← Tous les documents
            </Link>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight">Nouveau document</h1>
            <p class="mt-1 text-sm text-texte-doux">
                Enregistré en brouillon : rien n’est définitif tant que vous n’avez pas émis.
            </p>
        </div>
    </div>

    <p v-if="clients.length === 0" class="verre mt-6 rounded-2xl p-6 text-sm text-texte-doux">
        Aucun client enregistré. Ajoutez-en un avant de facturer —
        <Link href="/clients/nouveau" class="font-semibold text-accent hover:underline">créer un client</Link>.
    </p>

    <form v-else class="mt-6 grid gap-6 lg:grid-cols-3" @submit.prevent="enregistrer(false)">
        <div class="space-y-6 lg:col-span-2">
            <!-- L'en-tête du document -->
            <section class="verre rounded-2xl p-6">
                <h2 class="text-sm font-semibold">Le document</h2>

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="text-[13px] font-medium">Type</span>
                        <select v-model="formulaire.type" class="mt-1.5 w-full">
                            <option v-for="t in types" :key="t.valeur" :value="t.valeur">{{ t.libelle }}</option>
                        </select>
                    </label>

                    <label class="block">
                        <span class="text-[13px] font-medium">Client</span>
                        <select v-model="formulaire.client_id" class="mt-1.5 w-full">
                            <option v-for="c in clients" :key="c.id" :value="c.id">
                                {{ c.nom }}<template v-if="c.ville"> — {{ c.ville }}</template>
                            </option>
                        </select>
                        <span v-if="formulaire.errors.client_id" class="mt-1 block text-[13px] text-perte">
                            {{ formulaire.errors.client_id }}
                        </span>
                        <!-- Signalé AVANT la saisie : l'ICE manquant bloquera
                             l'émission d'une facture, et le découvrir après
                             avoir tout tapé est la pire des surprises. -->
                        <span
                            v-else-if="clientChoisi?.iceManquant && !estDevis"
                            class="mt-1 block text-[13px] text-texte-doux"
                        >
                            Ce client n’a pas d’ICE : une facture ne pourra pas être émise à son nom.
                        </span>
                    </label>

                    <label class="block">
                        <span class="text-[13px] font-medium">Date</span>
                        <input v-model="formulaire.date_emission" type="date" class="champ mt-1.5 w-full rounded-lg px-3 py-2 text-sm">
                        <span v-if="formulaire.errors.date_emission" class="mt-1 block text-[13px] text-perte">
                            {{ formulaire.errors.date_emission }}
                        </span>
                    </label>

                    <label class="block">
                        <span class="text-[13px] font-medium">
                            {{ estDevis ? 'Valable jusqu’au' : 'Échéance' }}
                        </span>
                        <input
                            v-model="formulaire.date_echeance"
                            type="date"
                            :placeholder="echeanceSuggeree"
                            class="champ mt-1.5 w-full rounded-lg px-3 py-2 text-sm"
                        >
                        <span class="mt-1 block text-[13px] text-texte-doux">
                            Laissée vide : {{ echeanceSuggeree }}
                            <template v-if="!estDevis && clientChoisi">
                                ({{ clientChoisi.delai }} jours)
                            </template>
                        </span>
                    </label>

                    <label class="block sm:col-span-2">
                        <span class="text-[13px] font-medium">Objet</span>
                        <input
                            v-model="formulaire.objet"
                            type="text"
                            placeholder="Refonte du site institutionnel"
                            class="champ mt-1.5 w-full rounded-lg px-3 py-2 text-sm"
                        >
                    </label>
                </div>
            </section>

            <!-- Les lignes -->
            <section class="verre rounded-2xl p-6">
                <div class="flex items-baseline justify-between">
                    <h2 class="text-sm font-semibold">Les lignes</h2>
                    <button type="button" class="text-[13px] font-semibold text-accent hover:underline" @click="ajouterLigne">
                        + Ajouter une ligne
                    </button>
                </div>

                <div class="mt-4 space-y-3">
                    <div
                        v-for="(ligne, i) in formulaire.lignes"
                        :key="i"
                        class="rounded-xl border border-bordure p-3"
                    >
                        <div class="grid gap-2 sm:grid-cols-12">
                            <input
                                v-model="ligne.designation"
                                type="text"
                                placeholder="Désignation"
                                class="champ rounded-lg px-3 py-2 text-sm sm:col-span-12"
                            >

                            <label class="sm:col-span-2">
                                <span class="mb-1 block text-[11px] uppercase tracking-wide text-texte-doux">Qté</span>
                                <input v-model="ligne.quantite" type="number" step="0.001" min="0.001" class="champ nombre w-full rounded-lg px-2 py-1.5 text-sm">
                            </label>

                            <label class="sm:col-span-2">
                                <span class="mb-1 block text-[11px] uppercase tracking-wide text-texte-doux">Unité</span>
                                <input v-model="ligne.unite" type="text" class="champ w-full rounded-lg px-2 py-1.5 text-sm">
                            </label>

                            <label class="sm:col-span-3">
                                <span class="mb-1 block text-[11px] uppercase tracking-wide text-texte-doux">Prix HT</span>
                                <input v-model="ligne.prix_unitaire_ht" type="number" step="0.01" min="0" placeholder="0,00" class="champ nombre w-full rounded-lg px-2 py-1.5 text-sm">
                            </label>

                            <label class="sm:col-span-2">
                                <span class="mb-1 block text-[11px] uppercase tracking-wide text-texte-doux">Remise %</span>
                                <input v-model="ligne.remise_pct" type="number" step="0.01" min="0" max="100" class="champ nombre w-full rounded-lg px-2 py-1.5 text-sm">
                            </label>

                            <label class="sm:col-span-3">
                                <span class="mb-1 block text-[11px] uppercase tracking-wide text-texte-doux">TVA</span>
                                <select v-model="ligne.taux_tva" class="w-full">
                                    <option v-for="taux in tauxTva" :key="taux" :value="taux">
                                        {{ taux === 0 ? 'Exonéré' : `${taux} %` }}
                                    </option>
                                </select>
                            </label>
                        </div>

                        <div class="mt-2 flex items-center gap-3">
                            <span class="nombre text-[13px] font-semibold">
                                {{ montant(arrondi((Number.parseFloat(ligne.quantite) || 0) * (Number.parseFloat(ligne.prix_unitaire_ht) || 0) * (1 - (Number.parseFloat(ligne.remise_pct) || 0) / 100))) }}
                                <span class="font-normal text-texte-doux">HT</span>
                            </span>

                            <span v-if="erreurLigne(i)" class="text-[13px] text-perte">{{ erreurLigne(i) }}</span>

                            <div class="ml-auto flex gap-3">
                                <button type="button" class="text-xs text-texte-doux hover:text-texte" @click="dupliquerLigne(i)">
                                    Dupliquer
                                </button>
                                <button type="button" class="text-xs text-texte-doux hover:text-perte" @click="retirerLigne(i)">
                                    Retirer
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="verre rounded-2xl p-6">
                <h2 class="text-sm font-semibold">Conditions et notes</h2>

                <label class="mt-4 block">
                    <span class="text-[13px] font-medium">Conditions imprimées sur le document</span>
                    <textarea v-model="formulaire.conditions" rows="2" class="champ mt-1.5 w-full rounded-lg px-3 py-2 text-sm" placeholder="Paiement par virement ou chèque à l’ordre de…" />
                </label>

                <label class="mt-4 block">
                    <span class="text-[13px] font-medium">Note interne</span>
                    <textarea v-model="formulaire.notes_internes" rows="2" class="champ mt-1.5 w-full rounded-lg px-3 py-2 text-sm" placeholder="Non imprimée sur le document." />
                </label>
            </section>
        </div>

        <!-- Le récapitulatif, qui suit la saisie -->
        <aside class="lg:sticky lg:top-24 lg:self-start">
            <section class="verre rounded-2xl p-6">
                <h2 class="text-sm font-semibold">Total</h2>

                <dl class="mt-4 space-y-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-texte-doux">Total HT</dt>
                        <dd class="nombre font-medium">{{ montant(totaux.ht) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-texte-doux">TVA</dt>
                        <dd class="nombre">{{ montant(totaux.tva) }}</dd>
                    </div>
                    <div class="flex justify-between border-t border-bordure pt-2 text-base">
                        <dt class="font-semibold">Total TTC</dt>
                        <dd class="nombre font-semibold">{{ montant(totaux.ttc) }} MAD</dd>
                    </div>
                </dl>

                <p class="mt-4 text-xs leading-relaxed text-texte-doux">
                    Aperçu calculé comme la base le fera : arrondi au centime ligne par ligne,
                    puis somme.
                </p>

                <div class="mt-5 space-y-2">
                    <button
                        type="submit"
                        :disabled="formulaire.processing"
                        class="bouton-discret w-full rounded-lg px-4 py-2.5 text-[13px] font-semibold disabled:opacity-50"
                    >
                        Enregistrer en brouillon
                    </button>

                    <button
                        type="button"
                        :disabled="formulaire.processing"
                        class="bouton-accent w-full rounded-lg px-4 py-2.5 text-[13px] disabled:opacity-50"
                        @click="enregistrer(true)"
                    >
                        {{ formulaire.processing ? 'Enregistrement…' : 'Créer et émettre' }}
                    </button>
                </div>

                <p class="mt-3 text-xs leading-relaxed text-texte-doux">
                    Émettre attribue le numéro de la série légale et fige le document.
                    C’est irréversible : la correction passe ensuite par un avoir.
                </p>

                <p v-if="formulaire.errors.lignes" class="mt-3 text-[13px] text-perte">
                    {{ formulaire.errors.lignes }}
                </p>
            </section>
        </aside>
    </form>
</template>
