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
        <div class="w-full max-w-[1400px] animate-in fade-in slide-in-from-bottom-8 duration-1000">

            <div class="flex flex-col lg:flex-row justify-between items-start lg:items-end gap-8 mb-12 border-b border-white/10 pb-8">

                <div class="flex gap-12">
                    <div>
                        <div class="text-[10px] uppercase text-[#22c55e] tracking-[0.3em] mb-2 flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full bg-[#22c55e] animate-pulse"></span>
                            System Status
                        </div>
                        <div class="font-mono text-xs text-white/50 space-y-1">
                            <p>CONNECTION: <span class="text-white">SECURE</span></p>
                            <p>LATENCY: <span class="text-white">12ms</span></p>
                            <p>ACTIVE NODES: <span class="text-white">124/150</span></p>
                        </div>
                    </div>

                    <div class="hidden md:block border-l border-white/10 pl-12">
                        <div class="text-[10px] uppercase text-white/30 tracking-[0.3em] mb-2">Operator</div>
                        <div class="font-bold text-sm tracking-widest uppercase text-white">GUEST_0451</div>
                        <div class="text-[10px] text-[#22c55e] mt-1 font-mono">Level: Authorized</div>
                    </div>
                </div>

                <div class="bg-black/50 border border-white/10 p-6 rounded-3xl flex items-center gap-8 backdrop-blur-md min-w-[320px]">
                    <div>
                        <span class="block text-[10px] uppercase text-white/30 tracking-[0.2em] mb-1">Account Balance</span>
                        <span class="text-4xl font-black italic tracking-tighter text-white">
                            {{ userBalance.toFixed(2) }} <span class="text-[#22c55e] text-2xl">₽</span>
                        </span>
                    </div>
                    <button class="ml-auto w-12 h-12 rounded-2xl bg-white/5 border border-white/10 text-[#22c55e] flex items-center justify-center hover:bg-[#22c55e] hover:text-black transition-all group">
                        <svg class="w-5 h-5 group-hover:scale-125 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                        </svg>
                    </button>
                </div>
            </div>

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
