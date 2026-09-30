<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3'
import Marque from '../../Marque.vue'

const formulaire = useForm({ mot_de_passe: '' })

function ouvrir() {
    formulaire.post('/documentation', {
        // Le mot de passe est vidé quoi qu'il arrive : à l'échec pour ne pas
        // le laisser traîner dans la mémoire du composant, au succès parce
        // qu'il n'a plus de raison d'y être.
        onFinish: () => formulaire.reset('mot_de_passe'),
    })
}
</script>

<template>
    <Head title="Documentation technique" />

    <div class="flex min-h-screen items-center justify-center px-6 py-16">
        <div class="w-full max-w-md">
            <Marque :taille="44" />

            <h1 class="mt-6 text-2xl font-semibold tracking-tight">Documentation technique</h1>
            <p class="mt-2 text-sm leading-relaxed text-texte-doux">
                Les choix de conception, ce que la base de données garantit et comment,
                et les pièges rencontrés en chemin. Plus détaillée que la visite guidée
                de l’application, et réservée.
            </p>

            <form class="mt-8" @submit.prevent="ouvrir">
                <label class="block">
                    <span class="text-[13px] font-medium">Mot de passe</span>
                    <input
                        v-model="formulaire.mot_de_passe"
                        type="password"
                        autocomplete="off"
                        required
                        autofocus
                        class="champ mt-1.5 w-full rounded-lg px-3 py-2.5 text-sm"
                    >
                    <span v-if="formulaire.errors.mot_de_passe" class="mt-1.5 block text-[13px] text-perte">
                        {{ formulaire.errors.mot_de_passe }}
                    </span>
                </label>

                <button
                    type="submit"
                    :disabled="formulaire.processing"
                    class="bouton-accent mt-4 w-full rounded-lg px-4 py-2.5 text-[13px] disabled:opacity-50"
                >
                    {{ formulaire.processing ? 'Vérification…' : 'Ouvrir' }}
                </button>
            </form>

            <p class="mt-8 text-xs leading-relaxed text-texte-faible">
                Le mot de passe n’est écrit nulle part dans le dépôt : il vient d’un
                fichier de configuration non versionné. La comparaison se fait en temps
                constant, et cinq essais par minute sont autorisés.
            </p>

            <Link href="/" class="mt-6 inline-block text-[13px] font-medium text-accent hover:underline">
                ← Retour à l’application
            </Link>
        </div>
    </div>
</template>
