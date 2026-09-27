<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import MainLayout from '@/Layouts/MainLayout.vue'
import { useClubName } from '@/Composables/useClubName'

defineProps<{
    manifest: { min_os: string; stack: string; available: boolean }
    sections: Array<{ id: number; title: string; body: string }>
}>()

const clubName = useClubName()
</script>

<template>
    <Head :title="`${clubName} | Играй с дома`" />
    <MainLayout>
        <div class="max-w-2xl mx-auto px-4 py-12 font-mono text-white space-y-6">
            <h1 class="text-4xl font-black uppercase italic tracking-tight">Играй с дома</h1>
            <p class="text-sm text-white/70 leading-relaxed">
                Поставь REACTOR AC на свой Windows ({{ manifest.min_os }}) и заходи на сервер клуба CS2.
                Пароль лобби одноразовый, его выдаёт живой клиент. Это Protocol Gate на {{ manifest.stack }}, не античит уровня FACEIT и не драйвер.
            </p>
            <Link href="/login" class="inline-block px-5 py-3 bg-cyan-400 text-black rounded-xl text-[11px] font-black uppercase tracking-widest">
                Войти и скачать
            </Link>
            <article v-for="section in sections" :key="section.id" class="border border-white/10 rounded-2xl p-5">
                <h2 class="text-[11px] uppercase tracking-[0.25em] text-cyan-300 font-black">{{ section.title }}</h2>
                <p class="mt-2 text-sm text-white/65 leading-relaxed">{{ section.body }}</p>
            </article>
        </div>
    </MainLayout>
</template>
