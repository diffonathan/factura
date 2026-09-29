<script setup>
import { Head } from '@inertiajs/vue3';

defineProps({
    laravel: String,
    php: String,
    base: String,
});
</script>

<template>
    <Head title="Accueil" />

    <main class="mx-auto max-w-2xl px-6 py-20">
        <h1 class="text-3xl font-semibold tracking-tight text-slate-900">Factura</h1>
        <p class="mt-3 text-slate-600">
            Facturation conforme au Maroc pour les TPE et les indépendants.
            Le socle est en place : Laravel rend une page Vue par Inertia, et
            les données ci-dessous viennent du serveur.
        </p>

        <dl class="mt-10 grid grid-cols-3 gap-4">
            <div class="rounded-xl border border-slate-200 p-4">
                <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Laravel</dt>
                <dd class="mt-1 font-mono text-lg text-slate-900">{{ laravel }}</dd>
            </div>
            <div class="rounded-xl border border-slate-200 p-4">
                <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">PHP</dt>
                <dd class="mt-1 font-mono text-lg text-slate-900">{{ php }}</dd>
            </div>
            <div class="rounded-xl border border-slate-200 p-4">
                <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Base</dt>
                <dd class="mt-1 font-mono text-lg text-slate-900">{{ base }}</dd>
            </div>
        </dl>
    </main>
</template>
