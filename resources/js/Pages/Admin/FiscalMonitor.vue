<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import axios from 'axios'
import MainLayout from '@/Layouts/MainLayout.vue'

const props = defineProps<{ initialStats: any }>()

const kktStatus = ref<any>(null)
const isLoading = ref(true)
const error = ref('')

const refreshStatus = async () => {
    isLoading.value = true
    try {
        const { data } = await axios.get('/admin/fiscal/hardware-status')
        kktStatus.value = data
        error.value = data.Error || ''
    } catch (e) {
        error.value = 'Связь с KkmServer потеряна'
    } finally {
        isLoading.value = false
    }
}

// Авто-обновление каждые 30 секунд
let interval: any = null
onMounted(() => {
    refreshStatus()
    interval = setInterval(refreshStatus, 30000)
})
onUnmounted(() => clearInterval(interval))
</script>

<template>
    <MainLayout>
        <div class="max-w-6xl mx-auto space-y-6">
            <div class="flex justify-between items-end">
                <div>
                    <h1 class="text-3xl font-black text-white uppercase italic tracking-tighter">Фискальный узел</h1>
                    <p class="text-white/30 text-xs uppercase tracking-widest mt-1">Мониторинг KkmServer & 54-ФЗ</p>
                </div>
                <button @click="refreshStatus" :disabled="isLoading"
                        class="px-6 py-2 bg-cyan-500/10 border border-cyan-500/30 text-cyan-500 text-[10px] font-black uppercase rounded-xl hover:bg-cyan-500 hover:text-black transition-all">
                    {{ isLoading ? 'Синхронизация...' : 'Обновить статус' }}
                </button>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2 bg-[#0a0a0a] border border-white/5 rounded-[40px] p-8 relative overflow-hidden">
                    <div v-if="kktStatus" class="space-y-8">
                        <div class="flex items-center gap-6">
                            <div class="w-20 h-20 rounded-3xl flex items-center justify-center border-2 transition-all shadow-[0_0_30px_rgba(0,0,0,0.5)]"
                                 :class="!error ? 'border-cyan-500/50 bg-cyan-500/10 text-cyan-500' : 'border-red-500/50 bg-red-500/10 text-red-500'">
                                <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                </svg>
                            </div>
                            <div>
                                <div class="text-2xl font-black text-white uppercase italic">{{ kktStatus.NameDevice || 'Устройство не найдено' }}</div>
                                <div class="flex items-center gap-2 mt-1">
                                    <span class="w-2 h-2 rounded-full" :class="!error ? 'bg-cyan-500 animate-pulse' : 'bg-red-500'"></span>
                                    <span class="text-[10px] font-black uppercase tracking-widest" :class="!error ? 'text-cyan-500' : 'text-red-500'">
                                        {{ !error ? 'В сети' : 'Ошибка связи' }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div class="p-4 bg-white/2 rounded-2xl border border-white/5">
                                <span class="text-[9px] text-white/20 uppercase font-black block mb-1">Бумага</span>
                                <span class="text-sm font-black italic" :class="kktStatus.PaperOut ? 'text-red-500' : 'text-white'">
                                    {{ kktStatus.PaperOut ? 'ЗАКОНЧИЛАСЬ' : 'В НАЛИЧИИ' }}
                                </span>
                            </div>
                            <div class="p-4 bg-white/2 rounded-2xl border border-white/5">
                                <span class="text-[9px] text-white/20 uppercase font-black block mb-1">Смена</span>
                                <span class="text-sm font-black text-white italic">
                                    {{ kktStatus.SessionOpened ? 'ОТКРЫТА' : 'ЗАКРЫТА' }}
                                </span>
                            </div>
                            <div class="p-4 bg-white/2 rounded-2xl border border-white/5">
                                <span class="text-[9px] text-white/20 uppercase font-black block mb-1">ФН</span>
                                <span class="text-sm font-black text-white italic uppercase">{{ kktStatus.RegistrationNumber || '—' }}</span>
                            </div>
                            <div class="p-4 bg-white/2 rounded-2xl border border-white/5">
                                <span class="text-[9px] text-white/20 uppercase font-black block mb-1">ОФД</span>
                                <span class="text-sm font-black text-white italic">ПОДКЛЮЧЕНО</span>
                            </div>
                        </div>

                        <div v-if="error" class="p-4 bg-red-500/10 border border-red-500/20 rounded-2xl text-red-500 font-mono text-xs italic">
                            CRITICAL_ERROR: {{ error }}
                        </div>
                    </div>
                </div>

                <div class="bg-[#0a0a0a] border border-white/5 rounded-[40px] p-8">
                    <h3 class="text-sm font-black text-white uppercase italic mb-6">Статистика 24h</h3>
                    <div class="space-y-4">
                        <div class="flex justify-between items-center p-4 bg-cyan-500/5 rounded-2xl border border-cyan-500/10">
                            <span class="text-[10px] text-cyan-500 font-black uppercase">Успешно</span>
                            <span class="text-xl font-black text-white italic">{{ initialStats.success }}</span>
                        </div>
                        <div class="flex justify-between items-center p-4 bg-yellow-500/5 rounded-2xl border border-yellow-500/10">
                            <span class="text-[10px] text-yellow-500 font-black uppercase">В очереди</span>
                            <span class="text-xl font-black text-white italic">{{ initialStats.pending }}</span>
                        </div>
                        <div class="flex justify-between items-center p-4 bg-red-500/5 rounded-2xl border border-red-500/10">
                            <span class="text-[10px] text-red-500 font-black uppercase">Ошибки</span>
                            <span class="text-xl font-black text-white italic">{{ initialStats.error }}</span>
                        </div>
                    </div>

                    <button class="w-full mt-8 py-4 border border-white/10 rounded-2xl text-[10px] text-white/30 font-black uppercase tracking-[0.3em] hover:bg-white/5 transition-all">
                        Распечатать X-отчет
                    </button>
                </div>
            </div>
        </div>
    </MainLayout>
</template>
