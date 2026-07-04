<script setup lang="ts">
import MainLayout from '@/Layouts/MainLayout.vue'
import { Link } from '@inertiajs/vue3'

// Принимаем данные из Laravel HomeController
defineProps<{
    userBalance: number;
    zones: Array<{
        id: string;
        name: string;
        desc: string;
        specs: Array<{ label: string; value: string }>;
        price: number;
        status: string;
    }>;
}>()
</script>

<template>
    <MainLayout>
        <div class="w-full max-w-[1400px] animate-in fade-in slide-in-from-bottom-8 duration-1000 pt-4">

            <div class="flex items-center gap-4 mb-8">
                <h2 class="text-2xl font-black italic uppercase tracking-widest text-white/80">Available Zones</h2>
                <div class="h-[1px] flex-grow bg-gradient-to-r from-white/10 to-transparent"></div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div v-for="zone in zones" :key="zone.id"
                     class="group relative bg-[#0a0a0a] border border-white/10 rounded-[2rem] overflow-hidden hover:border-[#22c55e]/50 transition-colors duration-500 flex flex-col">

                    <div class="absolute -bottom-10 -right-4 text-[12rem] leading-none font-black italic text-white/[0.02] select-none pointer-events-none group-hover:text-[#22c55e]/[0.02] transition-colors">
                        {{ zone.id }}
                    </div>

                    <div class="p-8 relative z-10 flex-grow flex flex-col">
                        <div class="flex justify-between items-start mb-6">
                            <div>
                                <div class="flex items-center gap-3 mb-1">
                                    <span class="text-[#22c55e] font-black italic text-xl">[{{ zone.id }}]</span>
                                    <h3 class="text-2xl font-black italic uppercase tracking-tighter text-white">{{ zone.name }}</h3>
                                </div>
                                <p class="text-xs text-white/40 max-w-[80%] leading-relaxed">{{ zone.desc }}</p>
                            </div>
                            <div class="px-3 py-1 rounded-full border text-[9px] uppercase tracking-widest font-bold"
                                 :class="zone.status === 'AVAILABLE' ? 'border-[#22c55e]/30 text-[#22c55e] bg-[#22c55e]/10' : 'border-orange-500/30 text-orange-500 bg-orange-500/10'">
                                {{ zone.status }}
                            </div>
                        </div>

                        <div class="mt-auto bg-black/40 border border-white/5 rounded-2xl p-5 mb-8">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-y-3 gap-x-6">
                                <div v-for="spec in zone.specs" :key="spec.label" class="flex items-center justify-between border-b border-white/5 pb-2 last:border-0 sm:last:border-b-0">
                                    <span class="text-[10px] text-white/30 uppercase tracking-widest font-mono">{{ spec.label }}</span>
                                    <span class="text-[11px] text-white/80 font-mono text-right">{{ spec.value }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-end justify-between pt-2">
                            <div>
                                <span class="block text-[10px] uppercase text-white/30 tracking-widest mb-1">Tariff rate</span>
                                <div class="flex items-baseline gap-2">
                                    <span class="text-4xl font-black italic leading-none text-white">{{ zone.price }}</span>
                                    <span class="text-sm text-[#22c55e] font-bold">₽<span class="text-white/30 font-normal text-xs uppercase tracking-widest ml-1">/ hr</span></span>
                                </div>
                            </div>

                            <Link href="/booking" class="px-8 py-4 bg-white/5 border border-white/10 rounded-xl font-bold uppercase text-[11px] tracking-[0.2em] text-white/60 hover:bg-[#22c55e] hover:text-black hover:border-[#22c55e] transition-all duration-300 inline-block text-center cursor-pointer">
                                Select
                            </Link>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </MainLayout>
</template>

<style scoped>
/* Плавное появление элементов */
.animate-in {
    animation-fill-mode: forwards;
}

/* Убираем стандартные стили ссылок браузера */
a {
    text-decoration: none;

}
</style>
