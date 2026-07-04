<script setup lang="ts">
import { ref, computed } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import axios from 'axios'

const props = defineProps<{
    incidents: any[]
}>()

// --- ФИЛЬТРАЦИЯ ---
const filter = ref('all') // 'all', 'high', 'medium'

const filteredIncidents = computed(() => {
    if (filter.value === 'all') return props.incidents
    return props.incidents.filter(i => i.severity === filter.value)
})

// --- ЛОГИКА УДАЛЕНИЯ/АРХИВАЦИИ (ТОЛЬКО ДЛЯ СУПЕРВИЗОРА) ---
const isProcessing = ref(false)

const resolveIncident = async (id: number) => {
    if (!confirm('Отметить инцидент как отработанный? Он исчезнет из активного лога.')) return

    isProcessing.value = true
    try {
        await axios.post(`/admin/api/incidents/${id}/resolve`)
        router.reload({ only: ['incidents'] })
    } catch (e) {
        alert('Ошибка доступа. Только высший уровень допуска может изменять лог.')
    } finally {
        isProcessing.value = false
    }
}

// Хелпер для дат
const formatDate = (dateStr: string) => {
    const d = new Date(dateStr)
    return d.toLocaleString('ru-RU', {
        day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit'
    })
}
</script>

<template>
    <Head title="РЕЕСТР ИНЦИДЕНТОВ" />

    <AdminLayout>
        <div class="max-w-7xl mx-auto space-y-8 animate-in fade-in duration-500 font-mono pb-20">

            <div class="flex flex-col md:flex-row md:items-end justify-between gap-6">
                <div>
                    <div class="flex items-center gap-4 mb-2">
                        <span class="w-3 h-3 bg-red-600 rounded-full animate-ping"></span>
                        <h1 class="text-4xl font-black italic text-white uppercase tracking-tighter">
                            Incident <span class="text-red-600">Log</span>
                        </h1>
                    </div>
                    <p class="text-white/20 text-[10px] uppercase tracking-[0.4em] font-bold">
                        Центральный реестр нарушений протокола обслуживания
                    </p>
                </div>

                <div class="flex bg-black border border-white/5 p-1 rounded-2xl h-fit">
                    <button v-for="f in ['all', 'high', 'medium']" :key="f"
                            @click="filter = f"
                            :class="[
                                'px-6 py-2 rounded-xl text-[9px] font-black uppercase tracking-widest transition-all',
                                filter === f ? 'bg-red-600 text-white shadow-[0_0_15px_rgba(220,38,38,0.3)]' : 'text-white/30 hover:text-white'
                            ]">
                        {{ f === 'all' ? 'Все' : (f === 'high' ? 'Критические' : 'Средние') }}
                    </button>
                </div>
            </div>

            <div class="bg-[#050505] border border-red-900/20 rounded-[3rem] overflow-hidden shadow-2xl relative">
                <div class="absolute inset-0 opacity-[0.03] pointer-events-none bg-[url('https://www.transparenttextures.com/patterns/carbon-fibre.png')]"></div>

                <div class="overflow-x-auto relative z-10">
                    <table class="w-full text-left border-collapse">
                        <thead>
                        <tr class="bg-red-950/20 text-red-500/50 text-[10px] uppercase font-black tracking-widest border-b border-white/5">
                            <th class="p-8">Время фиксации</th>
                            <th class="p-8">Тип / Уровень</th>
                            <th class="p-8">Детали нарушения</th>
                            <th class="p-8 text-right">Действие</th>
                        </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                        <tr v-for="incident in filteredIncidents" :key="incident.id"
                            class="group hover:bg-red-600/[0.02] transition-colors">

                            <td class="p-8">
                                <div class="text-white font-bold text-sm">{{ formatDate(incident.created_at) }}</div>
                                <div class="text-[9px] text-white/20 uppercase mt-1 italic">Auto-detected</div>
                            </td>

                            <td class="p-8">
                                <div class="flex flex-col gap-2">
                                        <span class="text-[10px] font-black text-white/60 uppercase italic tracking-tighter">
                                            {{ incident.type === 'late_order' ? 'Задержка сервиса' : 'Ручная правка' }}
                                        </span>
                                    <div :class="[
                                            'w-fit px-3 py-1 rounded-md text-[9px] font-black uppercase border shadow-sm',
                                            incident.severity === 'high'
                                                ? 'bg-red-600/10 border-red-600/50 text-red-500 animate-pulse'
                                                : 'bg-orange-500/10 border-orange-500/50 text-orange-500'
                                        ]">
                                        {{ incident.severity === 'high' ? 'Критично' : 'Внимание' }}
                                    </div>
                                </div>
                            </td>

                            <td class="p-8">
                                <p class="text-white/80 text-sm italic font-medium leading-relaxed max-w-md">
                                    «{{ incident.description }}»
                                </p>
                                <div v-if="incident.order_id" class="mt-2 inline-flex items-center gap-2 text-[9px] text-red-500/50 font-black uppercase tracking-widest border-b border-red-500/10 pb-1">
                                    Target ID: #{{ incident.order_id }}
                                </div>
                            </td>

                            <td class="p-8 text-right">
                                <button @click="resolveIncident(incident.id)"
                                        :disabled="isProcessing"
                                        class="p-4 bg-white/5 border border-white/10 rounded-2xl text-[10px] font-black uppercase tracking-widest text-white/30 hover:bg-red-600 hover:text-white hover:border-red-600 transition-all active:scale-95 disabled:opacity-30">
                                    Удалить из лога
                                </button>
                            </td>
                        </tr>
                        </tbody>
                    </table>

                    <div v-if="!filteredIncidents.length" class="p-32 text-center">
                        <div class="text-white/5 text-6xl mb-6">🛡️</div>
                        <h3 class="text-white/20 text-xl font-black uppercase italic tracking-[0.3em]">Система чиста</h3>
                        <p class="text-[9px] text-white/10 uppercase mt-4 tracking-widest">Все протоколы соблюдаются в штатном режиме</p>
                    </div>
                </div>
            </div>

            <div class="p-8 bg-red-600/5 border border-red-600/20 rounded-[2.5rem] flex items-center justify-between">
                <div class="flex items-center gap-6">
                    <div class="w-12 h-12 bg-red-600 text-black flex items-center justify-center rounded-2xl text-2xl">⚠️</div>
                    <div>
                        <div class="text-white font-black uppercase text-sm italic">Режим тотального контроля</div>
                        <p class="text-[9px] text-white/30 uppercase mt-1">Любое удаление записи фиксируется в логах ядра</p>
                    </div>
                </div>
                <div class="text-right">
                    <div class="text-2xl font-black text-red-600 italic tracking-tighter">{{ incidents.length }}</div>
                    <div class="text-[9px] text-white/20 uppercase font-black italic">Инцидентов всего</div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>

<style scoped>
@reference "../../../css/app.css";

.animate-in { animation: fade-in 0.5s ease-out forwards; }
@keyframes fade-in {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.custom-scrollbar::-webkit-scrollbar { width: 4px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(220, 38, 38, 0.1); border-radius: 10px; }
</style>
