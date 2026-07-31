<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const props = defineProps<{
    shifts: any[]
}>()

// Хелпер для дат
const formatDate = (dateString: string | null) => {
    if (!dateString) return '—'
    return new Date(dateString).toLocaleDateString('ru-RU', {
        day: '2-digit', month: 'short', year: 'numeric',
        hour: '2-digit', minute: '2-digit'
    })
}

// У открытой смены даты закрытия еще нет
const closedLabel = (shift: any) => shift.closed_at ? formatDate(shift.closed_at) : 'Смена активна'

const formatMoney = (value: any) => Number(value ?? 0).toLocaleString('ru-RU', {
    minimumFractionDigits: 2, maximumFractionDigits: 2
})

// Экранирование поля для CSV: кавычки удваиваются, всё оборачивается в кавычки
const csvCell = (value: any) => `"${String(value ?? '').replace(/"/g, '""')}"`

// Выгрузка того, что показано в таблице, в CSV (разделитель ";" — так Excel ru-RU
// открывает файл сразу по колонкам)
const exportCsv = () => {
    if (!props.shifts.length) return

    const headers = ['ID смены', 'Сотрудник', 'Открыта', 'Закрыта', 'Наличные в кассе']
    const rows = props.shifts.map(shift => [
        shift.id,
        shift.admin?.name || 'Неизвестно',
        formatDate(shift.opened_at),
        closedLabel(shift),
        Number(shift.cash_balance ?? 0).toFixed(2).replace('.', ',')
    ])

    const csv = [headers, ...rows]
        .map(row => row.map(csvCell).join(';'))
        .join('\r\n')

    // BOM нужен, чтобы Excel не поломал кириллицу
    const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `reactor-shifts-${new Date().toISOString().slice(0, 10)}.csv`
    link.click()
    URL.revokeObjectURL(url)
}
</script>

<template>
    <Head title="REACTOR | Архив смен" />
    <AdminLayout>
        <div class="max-w-7xl mx-auto space-y-8 animate-in fade-in duration-500 font-mono pb-20 px-4">

            <div class="flex justify-between items-end mb-10 border-b border-white/10 pb-6">
                <div>
                    <h1 class="text-3xl font-black uppercase italic text-white tracking-tighter">Shift <span class="text-blue-500">History</span></h1>
                    <p class="text-white/20 text-[10px] uppercase tracking-[0.4em] font-black mt-2 italic">Архив смен и кассовых отчетов</p>
                </div>
                <div class="flex gap-4">
                    <button @click="exportCsv" :disabled="shifts.length === 0"
                            :title="shifts.length === 0 ? 'Нет данных для выгрузки' : 'Выгрузить таблицу в CSV'"
                            class="px-5 py-3 border border-white/10 hover:border-blue-500 text-white/50 hover:text-white rounded-xl text-[10px] uppercase font-black tracking-widest transition-all disabled:opacity-30 disabled:hover:border-white/10 disabled:hover:text-white/50 disabled:cursor-not-allowed">
                        Экспорт CSV
                    </button>
                </div>
            </div>

            <div class="bg-[#050505] border border-white/5 rounded-[0.875rem] overflow-hidden shadow-xl">
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
                        <td class="p-6 text-xs">
                            <span v-if="shift.closed_at" class="text-white/50">{{ formatDate(shift.closed_at) }}</span>
                            <span v-else class="text-[#22c55e] text-[10px] uppercase font-black tracking-widest animate-pulse">Смена активна</span>
                        </td>
                        <td class="p-6 text-right text-blue-400 font-black">{{ formatMoney(shift.cash_balance) }} ₽</td>
                    </tr>
                    </tbody>
                    <tbody v-else>
                    <tr>
                        <td colspan="5" class="py-20 text-center">
                            <div class="text-white/10 text-2xl font-black uppercase tracking-widest italic mb-2">No Records Found</div>
                            <div class="text-white/30 text-[10px] uppercase tracking-widest">Архив смен пуст — записи появятся после первой приемки смены</div>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>

        </div>
    </AdminLayout>
</template>