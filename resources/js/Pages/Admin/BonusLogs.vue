<script setup lang="ts">
import { ref, computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

// Принимаем данные от Laravel (Inertia)
const props = defineProps<{
    logs: any[],
    stats: {
        today_minutes: number,
        month_minutes: number
    }
}>()

const searchQuery = ref('')

// Простой фильтр по имени гостя, телефону или причине
const filteredLogs = computed(() => {
    if (!searchQuery.value) return props.logs
    const q = searchQuery.value.toLowerCase()
    return props.logs.filter(log =>
        log.user?.name?.toLowerCase().includes(q) ||
        log.user?.phone?.includes(q) ||
        log.reason?.toLowerCase().includes(q) ||
        log.admin?.name?.toLowerCase().includes(q)
    )
})

// Форматирование даты
const formatDate = (dateStr: string) => {
    return new Date(dateStr).toLocaleString('ru-RU', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit'
    })
}
</script>

<template>
    <AdminLayout>
        <div class="max-w-7xl mx-auto space-y-8 animate-in fade-in duration-500">

            <div class="flex flex-col lg:flex-row gap-6">
                <div class="flex-1 bg-[#0a0a0a] border border-white/5 p-8 rounded-[2.5rem] shadow-xl flex flex-col justify-center">
                    <h1 class="text-3xl font-black uppercase italic text-cyan-500 tracking-tighter">Реестр Бонусов</h1>
                    <p class="text-white/30 text-[10px] uppercase tracking-[0.4em] font-black mt-1 italic">Журнал компенсаций времени</p>
                </div>

                <div class="flex gap-6">
                    <div class="bg-[#050505] border border-cyan-500/20 p-6 rounded-[2.5rem] min-w-[200px] flex flex-col justify-center shadow-[0_0_20px_rgba(6,182,212,0.05)]">
                        <span class="text-[10px] text-cyan-500/50 uppercase font-black tracking-[0.2em]">Выдано сегодня</span>
                        <div class="text-4xl font-black text-white mt-1 italic">{{ stats?.today_minutes || 0 }} <span class="text-sm text-cyan-500 ml-1">МИН</span></div>
                    </div>
                    <div class="bg-[#050505] border border-white/10 p-6 rounded-[2.5rem] min-w-[200px] flex flex-col justify-center">
                        <span class="text-[10px] text-white/30 uppercase font-black tracking-[0.2em]">За этот месяц</span>
                        <div class="text-4xl font-black text-white mt-1 italic">{{ stats?.month_minutes || 0 }} <span class="text-sm text-white/40 ml-1">МИН</span></div>
                    </div>
                </div>
            </div>

            <div class="bg-[#0a0a0a] border border-white/5 rounded-[2.5rem] p-8 shadow-xl">

                <div class="mb-8">
                    <input v-model="searchQuery" type="text" placeholder="Поиск по гостю, оператору или причине..."
                           class="w-full max-w-md bg-black border border-white/10 focus:border-cyan-500 rounded-xl px-5 py-4 text-white font-mono outline-none transition-all placeholder:text-white/20 text-sm" />
                </div>

                <div class="overflow-x-auto custom-scrollbar pb-4">
                    <table class="w-full text-left border-collapse">
                        <thead>
                        <tr class="border-b border-white/10">
                            <th class="py-4 px-4 text-[10px] text-white/30 uppercase font-black tracking-widest whitespace-nowrap">Дата и Время</th>
                            <th class="py-4 px-4 text-[10px] text-white/30 uppercase font-black tracking-widest whitespace-nowrap">Гость</th>
                            <th class="py-4 px-4 text-[10px] text-cyan-500 uppercase font-black tracking-widest whitespace-nowrap">Бонус</th>
                            <th class="py-4 px-4 text-[10px] text-red-400 uppercase font-black tracking-widest whitespace-nowrap">Причина (Обоснование)</th>
                            <th class="py-4 px-4 text-[10px] text-white/30 uppercase font-black tracking-widest whitespace-nowrap">Оператор</th>
                        </tr>
                        </thead>
                        <tbody class="font-mono text-sm">
                        <tr v-if="filteredLogs.length === 0">
                            <td colspan="5" class="py-12 text-center text-white/20 uppercase font-black tracking-[0.3em] italic border-b border-white/5">
                                Записи не найдены
                            </td>
                        </tr>
                        <tr v-for="log in filteredLogs" :key="log.id" class="border-b border-white/5 hover:bg-white/5 transition-colors group">
                            <td class="py-5 px-4 text-white/50">{{ formatDate(log.created_at) }}</td>
                            <td class="py-5 px-4">
                                <div class="text-white font-bold uppercase">{{ log.user?.name || 'Удален' }}</div>
                                <div class="text-[10px] text-white/30">{{ log.user?.phone || '---' }}</div>
                            </td>
                            <td class="py-5 px-4">
                                <div class="inline-flex items-center px-3 py-1 bg-cyan-500/10 border border-cyan-500/20 rounded-lg text-cyan-400 font-black italic">
                                    +{{ log.minutes }} мин
                                </div>
                            </td>
                            <td class="py-5 px-4 max-w-xs">
                                <div class="truncate group-hover:whitespace-normal group-hover:break-words text-white/80 transition-all">
                                    {{ log.reason }}
                                </div>
                            </td>
                            <td class="py-5 px-4 text-white/50 uppercase font-bold text-xs">
                                {{ log.admin?.name || 'Система' }}
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>

<style scoped>
@reference "../../../css/app.css";

.custom-scrollbar::-webkit-scrollbar { height: 6px; }
.custom-scrollbar::-webkit-scrollbar-track { background: rgba(0,0,0,0.2); border-radius: 10px; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 10px; }
.custom-scrollbar:hover::-webkit-scrollbar-thumb { background: rgba(6, 182, 212, 0.4); }
</style>
