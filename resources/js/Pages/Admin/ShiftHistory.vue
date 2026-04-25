<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const props = defineProps<{
    shifts: any[]
}>()

// Хелпер для дат
const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString('ru-RU', {
        day: '2-digit', month: 'short', year: 'numeric',
        hour: '2-digit', minute: '2-digit'
    })
}
</script>

<template>
    <Head title="REACTOR | Архив смен" />
    <AdminLayout>
        <div class="max-w-7xl mx-auto space-y-8 animate-in fade-in duration-500 font-mono pb-20 px-4">

            <div class="flex justify-between items-end mb-10 border-b border-white/10 pb-6">
                <div>
                    <h1 class="text-3xl font-black uppercase italic text-white tracking-tighter">Shift <span class="text-blue-500">History</span></h1>
                    <p class="text-white/20 text-[10px] uppercase tracking-[0.4em] font-black mt-2 italic">Архив закрытых смен и кассовых отчетов</p>
                </div>
                <div class="flex gap-4">
                    <button class="px-5 py-3 border border-white/10 hover:border-blue-500 text-white/50 hover:text-white rounded-xl text-[10px] uppercase font-black tracking-widest transition-all">
                        Экспорт CSV
                    </button>
                </div>
            </div>

            <div class="bg-[#050505] border border-white/5 rounded-[2rem] overflow-hidden shadow-xl">
                <table class="w-full text-left border-collapse">
                    <thead>
                    <tr class="border-b border-white/10 bg-white/[0.02]">
                        <th class="p-6 text-[10px] uppercase tracking-widest text-white/30 font-black">ID Смены</th>
                        <th class="p-6 text-[10px] uppercase tracking-widest text-white/30 font-black">Сотрудник</th>
                        <th class="p-6 text-[10px] uppercase tracking-widest text-white/30 font-black">Открыта</th>
                        <th class="p-6 text-[10px] uppercase tracking-widest text-white/30 font-black">Закрыта</th>
                        <th class="p-6 text-[10px] uppercase tracking-widest text-white/30 font-black text-right">Наличные в кассе</th>
                    </tr>
                    </thead>
                    <tbody v-if="shifts.length > 0">
                    <tr v-for="shift in shifts" :key="shift.id" class="border-b border-white/5 hover:bg-white/[0.02] transition-colors">
                        <td class="p-6 text-xs text-white font-black italic tracking-wider">#{{ shift.id }}</td>
                        <td class="p-6">
                            <div class="text-sm text-white font-bold uppercase tracking-tight">{{ shift.admin?.name || 'Неизвестно' }}</div>
                        </td>
                        <td class="p-6 text-xs text-white/50">{{ formatDate(shift.opened_at) }}</td>
                        <td class="p-6 text-xs text-white/50">{{ formatDate(shift.closed_at) }}</td>
                        <td class="p-6 text-right text-blue-400 font-black">{{ shift.cash_balance }} ₽</td>
                    </tr>
                    </tbody>
                    <tbody v-else>
                    <tr>
                        <td colspan="5" class="py-20 text-center">
                            <div class="text-white/10 text-2xl font-black uppercase tracking-widest italic mb-2">No Records Found</div>
                            <div class="text-white/30 text-[10px] uppercase tracking-widest">Архив смен пуст</div>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>

        </div>
    </AdminLayout>
</template>

<style scoped>
.animate-in { animation: fade-in 0.4s ease-out forwards; }
@keyframes fade-in {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>
