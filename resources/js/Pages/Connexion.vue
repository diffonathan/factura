<script setup>
import { Head, useForm } from '@inertiajs/vue3'

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
        <section class="hidden flex-col justify-between bg-encre p-12 text-papier lg:flex">
            <div class="flex items-center gap-2.5">
                <span class="grid h-9 w-9 place-items-center rounded-lg bg-caisse text-sm font-bold text-white">Fa</span>
                <span class="text-base font-semibold tracking-tight">Factura</span>
            </div>

            <div class="max-w-md">
                <h1 class="text-[2.1rem] font-semibold leading-[1.15] tracking-tight">
                    La facturation des TPE marocaines, sans les trous de numérotation.
                </h1>
                <p class="mt-5 text-[15px] leading-relaxed text-papier/65">
                    Une très petite entreprise facture encore sous Word ou Excel.
                    Toujours les mêmes conséquences : une numérotation qui saute,
                    des mentions légales oubliées, des impayés que personne ne relance.
                </p>

                <dl class="mt-10 space-y-5">
                    <div v-for="point in [
                        ['Numérotation continue', 'Le numéro est réservé en base, dans la même transaction que la facture. Un échec ne consomme rien.'],
                        ['Documents figés', 'Une facture émise ne se réécrit pas. On la corrige par un avoir — c\'est la loi, et la piste reste vérifiable.'],
                        ['Relances automatiques', 'Trois niveaux selon le retard, et jamais deux fois le même courrier.'],
                    ]" :key="point[0]" class="border-l-2 border-caisse pl-4">
                        <dt class="text-sm font-semibold">{{ point[0] }}</dt>
                        <dd class="mt-1 text-[13px] leading-relaxed text-papier/55">{{ point[1] }}</dd>
                    </div>
                </dl>
            </div>

            <p class="text-xs text-papier/40">
                Projet de démonstration — Laravel 13, Inertia, Vue 3, PostgreSQL.
            </p>
        </section>

        <section class="flex items-center justify-center px-6 py-16">
            <div class="w-full max-w-sm">
                <h2 class="text-2xl font-semibold tracking-tight">Connexion</h2>
                <p class="mt-2 text-sm text-encre-douce">
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
                            class="mt-1.5 w-full rounded-lg border border-trait bg-white px-3 py-2.5 text-sm outline-none transition-colors focus:border-caisse focus:ring-2 focus:ring-caisse/15"
                        >
                        <p v-if="formulaire.errors.email" class="mt-1.5 text-[13px] text-impaye">
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
                            class="mt-1.5 w-full rounded-lg border border-trait bg-white px-3 py-2.5 text-sm outline-none transition-colors focus:border-caisse focus:ring-2 focus:ring-caisse/15"
                        >
                    </div>

                    <label class="flex items-center gap-2 text-[13px] text-encre-douce">
                        <input
                            v-model="formulaire.se_souvenir"
                            type="checkbox"
                            class="h-4 w-4 rounded border-trait text-caisse focus:ring-caisse/30"
                        >
                        Rester connecté
                    </label>

                    <button
                        type="submit"
                        :disabled="formulaire.processing"
                        class="w-full rounded-lg bg-caisse px-4 py-2.5 text-sm font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-50"
                    >
                        {{ formulaire.processing ? 'Connexion…' : 'Se connecter' }}
                    </button>
                </form>

                <p class="mt-8 rounded-lg bg-papier-creux px-4 py-3 text-[13px] leading-relaxed text-encre-douce">
                    <span class="font-medium text-encre">Comptes de démonstration</span><br>
                    demo@factura.ma — propriétaire<br>
                    comptable@factura.ma — comptable<br>
                    Mot de passe : <span class="nombre">demonstration</span>
                </p>
            </div>
        </section>
    </div>
</template>
