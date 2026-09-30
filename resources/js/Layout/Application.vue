<script setup>
/**
 * La coque de l'application : en-tête, navigation, message de confirmation.
 *
 * Déclarée comme « layout persistant » par les pages qui l'utilisent, ce qui
 * fait qu'Inertia ne la démonte pas d'une page à l'autre. Concrètement : le
 * menu ne clignote pas à chaque navigation, et l'état local qu'il porterait
 * survivrait au changement de page.
 */
import { Link, usePage, router } from '@inertiajs/vue3'
import { computed, onMounted, ref, watch } from 'vue'
import Marque from '../Marque.vue'
import VisiteGuidee from '../VisiteGuidee.vue'
import { visiteJamaisFaite } from '../visite-guidee'

const page = usePage()

const utilisateur = computed(() => page.props.utilisateur)
const entreprise = computed(() => page.props.entreprise)

const liens = [
    { nom: 'Tableau de bord', url: '/', correspond: (u) => u === '/' },
    { nom: 'Documents', url: '/documents', correspond: (u) => u.startsWith('/documents') },
    { nom: 'Clients', url: '/clients', correspond: (u) => u.startsWith('/clients') },
]

const urlCourante = computed(() => page.url.split('?')[0])

// Le message de confirmation s'efface tout seul. Un bandeau qui reste à
// l'écran finit par être ignoré, y compris quand il annonce autre chose.
const message = ref(null)
let minuterie = null

watch(
    () => page.props.flash?.succes,
    (nouveau) => {
        if (!nouveau) return

        message.value = nouveau
        clearTimeout(minuterie)
        minuterie = setTimeout(() => (message.value = null), 5000)
    },
    { immediate: true },
)

function deconnecter() {
    router.post('/deconnexion')
}

/**
 * La visite guidée s'ouvre d'elle-même à la première venue.
 *
 * Un délai d'une demi-seconde, délibéré : ouvrir la visite avant que la page
 * soit peinte donne l'impression d'une fenêtre surgissante devant un écran
 * vide. Une demi-seconde suffit à ce que le visiteur voie d'abord
 * l'application, puis qu'on lui propose de l'expliquer.
 */
const visiteOuverte = ref(false)

onMounted(() => {
    if (visiteJamaisFaite()) {
        setTimeout(() => (visiteOuverte.value = true), 500)
    }
})
</script>

<template>
    <div class="min-h-full">
        <header class="sticky top-0 z-20 border-b border-bordure bg-fond/80 backdrop-blur">
            <div class="mx-auto flex h-16 max-w-7xl items-center gap-8 px-6">
                <Link href="/" class="flex items-center gap-2.5">
                    <Marque :taille="30" />
                    <span class="text-[15px] font-semibold tracking-tight">
                        Fact<span class="text-accent">ura</span>
                    </span>
                </Link>

                <nav class="flex items-center gap-1">
                    <Link
                        v-for="lien in liens"
                        :key="lien.url"
                        :href="lien.url"
                        class="rounded-lg px-3 py-1.5 text-sm font-medium transition-colors"
                        :data-visite="lien.url === '/documents' ? 'documents' : null"
                        :class="lien.correspond(urlCourante)
                            ? 'bg-surface text-texte'
                            : 'text-texte-doux hover:text-texte'"
                    >
                        {{ lien.nom }}
                    </Link>
                </nav>

                <div v-if="utilisateur" class="ml-auto flex items-center gap-4">
                    <button
                        type="button"
                        class="bouton-discret grid h-8 w-8 place-items-center rounded-full text-[13px] font-bold"
                        title="Revoir la visite guidée"
                        aria-label="Revoir la visite guidée"
                        @click="visiteOuverte = true"
                    >
                        ?
                    </button>

                    <div class="hidden text-right sm:block">
                        <p class="text-[13px] font-medium leading-tight">{{ entreprise?.raison_sociale }}</p>
                        <p class="text-[11px] uppercase tracking-wide text-texte-doux">
                            {{ utilisateur.nom }} · {{ utilisateur.role?.toLowerCase() }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="bouton-discret rounded-lg px-3 py-1.5 text-[13px] font-medium"
                        @click="deconnecter"
                    >
                        Quitter
                    </button>
                </div>
            </div>
        </header>

        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="-translate-y-2 opacity-0"
            leave-active-class="transition duration-150 ease-in"
            leave-to-class="-translate-y-2 opacity-0"
        >
            <div v-if="message" class="mx-auto max-w-7xl px-6 pt-5">
                <p class="rounded-xl border border-accent-bordure bg-gain-doux px-4 py-3 text-sm font-medium text-accent">
                    {{ message }}
                </p>
            </div>
        </Transition>

        <main class="mx-auto max-w-7xl px-6 py-8">
            <slot />
        </main>

        <VisiteGuidee :ouverte="visiteOuverte" @fermer="visiteOuverte = false" />

        <footer class="mx-auto max-w-7xl px-6 pb-10 pt-4">
            <p class="border-t border-bordure pt-5 text-xs leading-relaxed text-texte-doux">
                Projet de démonstration — les entreprises et les clients sont inventés.
                La numérotation, les mentions légales et les taux de TVA suivent la
                réglementation marocaine.
            </p>
        </footer>
    </div>
</template>
