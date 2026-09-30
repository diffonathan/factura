<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import Application from '../../Layout/Application.vue'
import { montant } from '../../format'

defineOptions({ layout: Application })

const props = defineProps({
    clients: Array,
    recherche: String,
})

const terme = ref(props.recherche ?? '')
let minuterie = null

watch(terme, (valeur) => {
    clearTimeout(minuterie)
    minuterie = setTimeout(() => {
        router.get('/clients', { recherche: valeur }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        })
    }, 350)
})
</script>

<template>
    <Head title="Clients" />

    <div class="flex flex-wrap items-baseline justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Clients</h1>
            <p class="mt-1 text-sm text-encre-douce">
                {{ clients.length }} client{{ clients.length > 1 ? 's' : '' }} ·
                encours et historique de facturation
            </p>
        </div>

        <input
            v-model="terme"
            type="search"
            placeholder="Nom, ICE ou ville…"
            class="w-64 rounded-lg border border-trait bg-white px-3 py-2 text-sm outline-none transition-colors focus:border-caisse focus:ring-2 focus:ring-caisse/15"
        >
    </div>

    <section class="mt-5 overflow-hidden rounded-2xl border border-trait bg-white">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-trait text-left text-[11px] uppercase tracking-wider text-encre-douce">
                    <th class="px-5 py-3 font-semibold">Client</th>
                    <th class="px-5 py-3 font-semibold">ICE</th>
                    <th class="px-5 py-3 font-semibold">Contact</th>
                    <th class="px-5 py-3 text-right font-semibold">Délai</th>
                    <th class="px-5 py-3 text-right font-semibold">Factures</th>
                    <th class="px-5 py-3 text-right font-semibold">Facturé TTC</th>
                    <th class="px-5 py-3 text-right font-semibold">Encours</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="client in clients"
                    :key="client.id"
                    class="border-b border-trait last:border-0 transition-colors hover:bg-papier-creux/60"
                >
                    <td class="px-5 py-3">
                        <Link :href="`/documents?recherche=${encodeURIComponent(client.nom)}`"
                              class="font-medium hover:text-caisse">
                            {{ client.nom }}
                        </Link>
                        <span class="block text-xs text-encre-douce">
                            {{ client.ville }}
                            <template v-if="client.est_particulier"> · particulier</template>
                        </span>
                    </td>
                    <td class="nombre px-5 py-3 text-[13px] text-encre-douce">
                        {{ client.ice ?? '—' }}
                    </td>
                    <td class="px-5 py-3 text-[13px] text-encre-douce">
                        {{ client.email }}
                        <span class="nombre block text-xs">{{ client.telephone }}</span>
                    </td>
                    <td class="nombre px-5 py-3 text-right text-[13px] text-encre-douce">
                        {{ client.delai }} j
                    </td>
                    <td class="nombre px-5 py-3 text-right text-[13px]">{{ client.factures }}</td>
                    <td class="nombre px-5 py-3 text-right font-medium">{{ montant(client.total) }}</td>
                    <td class="nombre px-5 py-3 text-right"
                        :class="Number.parseFloat(client.reste) > 0 ? 'font-semibold text-echeance' : 'text-encre-douce'">
                        {{ montant(client.reste) }}
                    </td>
                </tr>

                <tr v-if="clients.length === 0">
                    <td colspan="7" class="px-5 py-12 text-center text-sm text-encre-douce">
                        Aucun client ne correspond à cette recherche.
                    </td>
                </tr>
            </tbody>
        </table>
    </section>
</template>
