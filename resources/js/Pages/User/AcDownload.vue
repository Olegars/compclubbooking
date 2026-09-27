<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import MainLayout from '@/Layouts/MainLayout.vue'
import { useClubName } from '@/Composables/useClubName'

defineProps<{
    manifest: { version: string; sha256: string | null; min_os: string; available: boolean; stack: string }
    reactor_ac: { mode: string; status: string; notice: string }
    sections: Array<{ id: number; title: string; body: string }>
}>()

const clubName = useClubName()
</script>

<template>
    <Head :title="`${clubName} | REACTOR AC`" />
    <MainLayout>
        <div class="max-w-2xl mx-auto px-4 py-10 font-mono text-white space-y-6">
            <div>
                <h1 class="text-3xl font-black uppercase italic text-cyan-400">REACTOR AC</h1>
                <p class="mt-3 text-sm text-white/70 leading-relaxed">
                    Клиент для домашнего Windows. На сервер клуба пускает Protocol Gate ({{ manifest.stack }}), это не античит уровня FACEIT.
                    Сборка: {{ manifest.version }}, {{ manifest.min_os }}.
                </p>
            </div>
            <div class="border border-white/10 rounded-2xl p-5 bg-white/5">
                <p v-if="manifest.available" class="text-sm">Файл готов. sha256 {{ manifest.sha256 }}</p>
                <p v-else class="text-sm text-white/70">
                    Установщик ещё не выложен. Когда сборка появится, она будет на этой странице, с sha256 в /ac.json.
                </p>
                <p class="mt-2 text-[11px] uppercase tracking-widest text-white/40">Режим клуба: {{ reactor_ac.mode }} · {{ reactor_ac.status }}</p>
            </div>
            <article v-for="section in sections" :key="section.id" class="border border-white/10 rounded-2xl p-5">
                <h2 class="text-[11px] uppercase tracking-[0.25em] text-cyan-300 font-black">{{ section.title }}</h2>
                <p class="mt-2 text-sm text-white/70 leading-relaxed whitespace-pre-wrap">{{ section.body }}</p>
            </article>
        </div>
    </MainLayout>
</template>
