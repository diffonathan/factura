<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3'
import { watch } from 'vue'
import Application from '../../Layout/Application.vue'

defineOptions({ layout: Application })

const formulaire = useForm({
    nom: '',
    est_particulier: false,
    ice: '',
    identifiant_fiscal: '',
    adresse: '',
    ville: '',
    telephone: '',
    email: '',
    delai_paiement_jours: 30,
    notes: '',
})

// Un particulier n'a ni ICE ni identifiant fiscal, et règle en général
// comptant. On vide et on ajuste plutôt que de laisser des champs contredire
// la case cochée — la base refuserait, et le message serait obscur.
watch(() => formulaire.est_particulier, (particulier) => {
    if (!particulier) return

    formulaire.ice = ''
    formulaire.identifiant_fiscal = ''
    formulaire.delai_paiement_jours = 0
})

const VILLES = ['Casablanca', 'Rabat', 'Marrakech', 'Tanger', 'Fès', 'Agadir', 'Meknès', 'Oujda', 'Kénitra', 'Tétouan']
</script>

<template>
    <Head title="Nouveau client" />

    <div>
        <Link href="/clients" class="text-[13px] font-medium text-texte-doux hover:text-texte">
            ← Tous les clients
        </Link>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight">Nouveau client</h1>
        <p class="mt-1 text-sm text-texte-doux">
            L’ICE est exigé sur une facture entre professionnels : sans lui, le client ne
            peut pas récupérer la TVA.
        </p>
    </div>

    <form class="mt-6 max-w-2xl" @submit.prevent="formulaire.post('/clients')">
        <section class="verre rounded-2xl p-6">
            <label class="flex items-center gap-2.5 text-[13px]">
                <input v-model="formulaire.est_particulier" type="checkbox" class="h-4 w-4 rounded border-bordure-vive text-accent">
                <span>C’est un particulier</span>
                <span class="text-texte-doux">— pas d’ICE, règlement comptant</span>
            </label>

            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <label class="block sm:col-span-2">
                    <span class="text-[13px] font-medium">Nom{{ formulaire.est_particulier ? '' : ' ou raison sociale' }}</span>
                    <input v-model="formulaire.nom" type="text" required class="champ mt-1.5 w-full rounded-lg px-3 py-2 text-sm">
                    <span v-if="formulaire.errors.nom" class="mt-1 block text-[13px] text-perte">{{ formulaire.errors.nom }}</span>
                </label>

                <template v-if="!formulaire.est_particulier">
                    <label class="block">
                        <span class="text-[13px] font-medium">ICE</span>
                        <input
                            v-model="formulaire.ice"
                            type="text"
                            inputmode="numeric"
                            maxlength="15"
                            placeholder="15 chiffres"
                            class="champ nombre mt-1.5 w-full rounded-lg px-3 py-2 text-sm"
                        >
                        <span v-if="formulaire.errors.ice" class="mt-1 block text-[13px] text-perte">{{ formulaire.errors.ice }}</span>
                    </label>

                    <label class="block">
                        <span class="text-[13px] font-medium">Identifiant fiscal</span>
                        <input v-model="formulaire.identifiant_fiscal" type="text" class="champ nombre mt-1.5 w-full rounded-lg px-3 py-2 text-sm">
                    </label>
                </template>

                <label class="block sm:col-span-2">
                    <span class="text-[13px] font-medium">Adresse</span>
                    <input v-model="formulaire.adresse" type="text" class="champ mt-1.5 w-full rounded-lg px-3 py-2 text-sm">
                </label>

                <label class="block">
                    <span class="text-[13px] font-medium">Ville</span>
                    <input v-model="formulaire.ville" type="text" list="villes" class="champ mt-1.5 w-full rounded-lg px-3 py-2 text-sm">
                    <!-- Une liste de suggestions, pas une liste fermée : on
                         propose les grandes villes sans interdire les autres. -->
                    <datalist id="villes">
                        <option v-for="ville in VILLES" :key="ville" :value="ville" />
                    </datalist>
                </label>

                <label class="block">
                    <span class="text-[13px] font-medium">Délai de paiement</span>
                    <input v-model="formulaire.delai_paiement_jours" type="number" min="0" max="120" class="champ nombre mt-1.5 w-full rounded-lg px-3 py-2 text-sm">
                    <span class="mt-1 block text-[13px] text-texte-doux">
                        En jours. La loi 69-21 plafonne à 60, ou 120 par accord écrit.
                    </span>
                    <span v-if="formulaire.errors.delai_paiement_jours" class="mt-1 block text-[13px] text-perte">
                        {{ formulaire.errors.delai_paiement_jours }}
                    </span>
                </label>

                <label class="block">
                    <span class="text-[13px] font-medium">Téléphone</span>
                    <input v-model="formulaire.telephone" type="tel" class="champ mt-1.5 w-full rounded-lg px-3 py-2 text-sm">
                </label>

                <label class="block sm:col-span-2">
                    <span class="text-[13px] font-medium">Courriel</span>
                    <input v-model="formulaire.email" type="email" class="champ mt-1.5 w-full rounded-lg px-3 py-2 text-sm">
                    <span class="mt-1 block text-[13px] text-texte-doux">
                        C’est à cette adresse que partiront les relances.
                    </span>
                    <span v-if="formulaire.errors.email" class="mt-1 block text-[13px] text-perte">{{ formulaire.errors.email }}</span>
                </label>
            </div>

            <div class="mt-6 flex items-center gap-3">
                <button type="submit" :disabled="formulaire.processing" class="bouton-accent rounded-lg px-4 py-2.5 text-[13px] disabled:opacity-50">
                    {{ formulaire.processing ? 'Enregistrement…' : 'Enregistrer le client' }}
                </button>
                <Link href="/clients" class="text-[13px] font-medium text-texte-doux hover:text-texte">Annuler</Link>
            </div>
        </section>
    </form>
</template>
