<script setup>
/**
 * L'affichage de la visite guidée.
 *
 * Deux formes selon l'étape : une carte au centre pour ce qui s'explique
 * (le problème, la loi, les technologies), et une bulle accrochée à un
 * élément de la page pour ce qui se montre.
 *
 * Le repli compte autant que le cas nominal : si la cible d'une étape est
 * absente — écran étroit, page différente, élément renommé — l'étape
 * s'affiche au centre au lieu de pointer dans le vide. Une visite guidée qui
 * casse est pire que pas de visite : elle donne l'impression que le reste
 * casse aussi.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import Marque from './Marque.vue'
import { ETAPES, marquerVisiteFaite } from './visite-guidee'

const props = defineProps({
    ouverte: { type: Boolean, default: false },
})

const emit = defineEmits(['fermer'])

const index = ref(0)
const zone = ref(null)

const etape = computed(() => ETAPES[index.value])
const derniere = computed(() => index.value === ETAPES.length - 1)

/** Position de l'élément mis en avant, en coordonnées de fenêtre. */
async function reperer() {
    zone.value = null

    if (!etape.value?.cible) return

    await nextTick()
    const el = document.querySelector(etape.value.cible)
    if (!el) return

    // On fait d'abord venir l'élément à l'écran, sinon la bulle se place
    // correctement sur un élément que personne ne voit.
    el.scrollIntoView({ block: 'center', behavior: 'smooth' })
    await new Promise((r) => setTimeout(r, 320))

    const r = el.getBoundingClientRect()
    if (r.width === 0 || r.height === 0) return

    zone.value = { haut: r.top, gauche: r.left, largeur: r.width, hauteur: r.height }
}

/** La bulle passe sous la cible, ou au-dessus s'il n'y a pas la place. */
const bulle = computed(() => {
    if (!zone.value) return null

    const marge = 14
    const hauteurBulle = 260
    const dessous = zone.value.haut + zone.value.hauteur + marge
    const place = window.innerHeight - dessous > hauteurBulle

    return {
        top: place ? `${dessous}px` : `${Math.max(marge, zone.value.haut - hauteurBulle - marge)}px`,
        left: `${Math.min(Math.max(marge, zone.value.gauche), window.innerWidth - 440 - marge)}px`,
    }
})

function suivant() {
    if (derniere.value) return terminer()
    index.value += 1
}

function precedent() {
    if (index.value > 0) index.value -= 1
}

function terminer() {
    marquerVisiteFaite()
    emit('fermer')
}

function auClavier(evenement) {
    if (!props.ouverte) return

    if (evenement.key === 'Escape') terminer()
    if (evenement.key === 'ArrowRight') suivant()
    if (evenement.key === 'ArrowLeft') precedent()
}

watch(() => props.ouverte, (ouverte) => {
    if (ouverte) {
        index.value = 0
        reperer()
    }
})

watch(index, reperer)

onMounted(() => {
    window.addEventListener('keydown', auClavier)
    window.addEventListener('resize', reperer)
})

onBeforeUnmount(() => {
    window.removeEventListener('keydown', auClavier)
    window.removeEventListener('resize', reperer)
})
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="opacity-0"
            leave-active-class="transition duration-200 ease-in"
            leave-to-class="opacity-0"
        >
            <div v-if="ouverte" class="fixed inset-0 z-50" role="dialog" aria-modal="true" aria-label="Visite guidée">
                <!-- Le voile. Plus opaque quand rien n'est mis en avant :
                     l'étape se lit alors comme une page, pas comme une bulle. -->
                <div
                    class="absolute inset-0 bg-black/75 backdrop-blur-[2px]"
                    @click="terminer"
                />

                <!-- La découpe autour de l'élément montré. Une ombre portée
                     démesurée assombrit tout SAUF l'intérieur du cadre : c'est
                     le moyen le plus simple d'obtenir un trou dans un voile,
                     sans masque SVG ni second calque. -->
                <div
                    v-if="zone"
                    class="pointer-events-none absolute rounded-xl ring-2 ring-accent transition-all duration-300"
                    :style="{
                        top: `${zone.haut - 6}px`,
                        left: `${zone.gauche - 6}px`,
                        width: `${zone.largeur + 12}px`,
                        height: `${zone.hauteur + 12}px`,
                        boxShadow: '0 0 0 9999px rgba(0,0,0,0.75)',
                    }"
                />

                <!-- L'étape : accrochée à la cible, ou au centre. -->
                <div
                    class="verre-modale absolute w-[min(440px,calc(100vw-2rem))] rounded-2xl p-6"
                    :style="bulle ?? {
                        top: '50%',
                        left: '50%',
                        transform: 'translate(-50%, -50%)',
                        width: 'min(560px, calc(100vw - 2rem))',
                    }"
                >
                    <div v-if="etape.marque" class="mb-5 flex items-center gap-3">
                        <Marque :taille="40" />
                        <span class="text-lg font-semibold tracking-tight">
                            Fact<span class="text-accent">ura</span>
                        </span>
                    </div>

                    <p class="text-[11px] font-semibold uppercase tracking-wider text-accent">
                        Étape {{ index + 1 }} sur {{ ETAPES.length }}
                    </p>

                    <h2 class="mt-1.5 text-xl font-semibold tracking-tight">{{ etape.titre }}</h2>

                    <div class="mt-3 space-y-3">
                        <p
                            v-for="(paragraphe, i) in etape.corps"
                            :key="i"
                            class="text-[14px] leading-relaxed text-texte-doux"
                        >
                            {{ paragraphe }}
                        </p>
                    </div>

                    <!-- Les technologies : repérables d'un coup d'œil, sans
                         avoir à lire les paragraphes au-dessus. -->
                    <ul v-if="etape.technologies" class="mt-5 flex flex-wrap gap-1.5">
                        <li
                            v-for="techno in etape.technologies"
                            :key="techno"
                            class="pastille pastille-accent"
                        >
                            {{ techno }}
                        </li>
                    </ul>

                    <div v-if="etape.liens" class="mt-5 flex flex-wrap gap-3">
                        <a
                            v-for="lien in etape.liens"
                            :key="lien.url"
                            :href="lien.url"
                            target="_blank"
                            rel="noopener"
                            class="text-[13px] font-semibold text-accent underline underline-offset-4 hover:text-accent-clair"
                        >
                            {{ lien.texte }} ↗
                        </a>
                    </div>

                    <div class="mt-6 flex items-center gap-3">
                        <!-- La progression, en points. Cliquables : quelqu'un
                             qui revoit la visite veut sauter à l'étape qui
                             l'intéresse, pas tout refaire. -->
                        <div class="flex gap-1.5">
                            <button
                                v-for="(pas, i) in ETAPES"
                                :key="i"
                                type="button"
                                class="h-1.5 rounded-full transition-all duration-300"
                                :class="i === index ? 'w-5 bg-accent' : 'w-1.5 bg-bordure-vive hover:bg-texte-faible'"
                                :aria-label="`Aller à l'étape ${i + 1} : ${pas.titre}`"
                                @click="index = i"
                            />
                        </div>

                        <div class="ml-auto flex items-center gap-2">
                            <button
                                v-if="index > 0"
                                type="button"
                                class="bouton-discret rounded-lg px-3 py-1.5 text-[13px] font-medium"
                                @click="precedent"
                            >
                                Retour
                            </button>
                            <button
                                type="button"
                                class="bouton-accent rounded-lg px-4 py-1.5 text-[13px]"
                                @click="suivant"
                            >
                                {{ derniere ? 'Commencer' : 'Suivant' }}
                            </button>
                        </div>
                    </div>

                    <button
                        v-if="!etape.final"
                        type="button"
                        class="mt-4 text-xs text-texte-faible underline underline-offset-4 hover:text-texte-doux"
                        @click="terminer"
                    >
                        Passer la visite
                    </button>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
