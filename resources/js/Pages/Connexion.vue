<script setup>
import { Head, useForm } from '@inertiajs/vue3'
import Marque from '../Marque.vue'

const formulaire = useForm({
    email: 'demo@factura.ma',
    mot_de_passe: 'demonstration',
    se_souvenir: false,
})

function envoyer() {
    // Le mot de passe est vidé quel que soit le résultat : à l'échec pour ne
    // pas le laisser dans la mémoire du composant, au succès parce qu'il n'a
    // plus lieu d'y être.
    formulaire.post('/connexion', {
        onFinish: () => formulaire.reset('mot_de_passe'),
    })
}
</script>

<template>
    <Head title="Connexion" />

    <div class="grid min-h-screen lg:grid-cols-2">
        <!-- Le volet de gauche dit ce que fait le produit. Un écran de
             connexion nu ne renseigne personne, et c'est souvent la première
             page qu'un visiteur voit. -->
        <section class="hidden flex-col justify-between bg-surface-haute p-12 text-texte lg:flex">
            <div class="flex items-center gap-2.5">
                <Marque :taille="36" />
                <span class="text-base font-semibold tracking-tight">
                    Fact<span class="text-accent">ura</span>
                </span>
            </div>

            <div class="max-w-md">
                <h1 class="text-[2.1rem] font-semibold leading-[1.15] tracking-tight">
                    La facturation des TPE marocaines, sans les trous de numérotation.
                </h1>
                <p class="mt-5 text-[15px] leading-relaxed text-texte-doux">
                    Une très petite entreprise facture encore sous Word ou Excel.
                    Toujours les mêmes conséquences : une numérotation qui saute,
                    des mentions légales oubliées, des impayés que personne ne relance.
                </p>

                <dl class="mt-10 space-y-5">
                    <div v-for="point in [
                        ['Numérotation continue', 'Le numéro est réservé en base, dans la même transaction que la facture. Un échec ne consomme rien.'],
                        ['Documents figés', 'Une facture émise ne se réécrit pas. On la corrige par un avoir — c\'est la loi, et la piste reste vérifiable.'],
                        ['Relances automatiques', 'Trois niveaux selon le retard, et jamais deux fois le même courrier.'],
                    ]" :key="point[0]" class="border-l-2 border-accent pl-4">
                        <dt class="text-sm font-semibold">{{ point[0] }}</dt>
                        <dd class="mt-1 text-[13px] leading-relaxed text-texte-doux">{{ point[1] }}</dd>
                    </div>
                </dl>
            </div>

            <p class="text-xs text-texte-faible">
                Projet de démonstration — Laravel 13, Inertia, Vue 3, PostgreSQL.
            </p>
        </section>

        <section class="flex items-center justify-center px-6 py-16">
            <div class="w-full max-w-sm">
                <h2 class="text-2xl font-semibold tracking-tight">Connexion</h2>
                <p class="mt-2 text-sm text-texte-doux">
                    Les identifiants de démonstration sont déjà saisis.
                </p>

                <form class="mt-8 space-y-5" @submit.prevent="envoyer">
                    <div>
                        <label for="email" class="block text-[13px] font-medium">Adresse électronique</label>
                        <input
                            id="email"
                            v-model="formulaire.email"
                            type="email"
                            autocomplete="username"
                            required
                            class="champ mt-1.5 w-full rounded-lg px-3 py-2.5 text-sm"
                        >
                        <p v-if="formulaire.errors.email" class="mt-1.5 text-[13px] text-perte">
                            {{ formulaire.errors.email }}
                        </p>
                    </div>

                    <div>
                        <label for="mot_de_passe" class="block text-[13px] font-medium">Mot de passe</label>
                        <input
                            id="mot_de_passe"
                            v-model="formulaire.mot_de_passe"
                            type="password"
                            autocomplete="current-password"
                            required
                            class="champ mt-1.5 w-full rounded-lg px-3 py-2.5 text-sm"
                        >
                    </div>

                    <label class="flex items-center gap-2 text-[13px] text-texte-doux">
                        <input
                            v-model="formulaire.se_souvenir"
                            type="checkbox"
                            class="h-4 w-4 rounded border-bordure text-accent focus:ring-accent-doux"
                        >
                        Rester connecté
                    </label>

                    <button
                        type="submit"
                        :disabled="formulaire.processing"
                        class="bouton-accent w-full rounded-lg px-4 py-2.5 text-sm disabled:opacity-50"
                    >
                        {{ formulaire.processing ? 'Connexion…' : 'Se connecter' }}
                    </button>
                </form>

                <p class="mt-8 rounded-lg bg-surface px-4 py-3 text-[13px] leading-relaxed text-texte-doux">
                    <span class="font-medium text-texte">Comptes de démonstration</span><br>
                    demo@factura.ma — propriétaire<br>
                    comptable@factura.ma — comptable<br>
                    Mot de passe : <span class="nombre">demonstration</span>
                </p>
            </div>
        </section>
    </div>
</template>
