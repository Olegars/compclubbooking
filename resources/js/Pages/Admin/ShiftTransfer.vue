<script setup lang="ts">
import { ref, computed } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const props = defineProps<{ expected: any[] }>()

const items = ref(props.expected.map(i => ({
    ...i,
    actual: i.stock
})))

const hasDiscrepancies = computed(() => items.value.some(i => i.actual !== i.stock))

const printList = () => { window.print() }

const submitShift = () => {
    const msg = hasDiscrepancies.value
        ? "ВНИМАНИЕ: Обнаружены расхождения! Зафиксировать инцидент и открыть смену?"
        : "Открыть новую смену?"

    if (confirm(msg)) {
        router.post('/admin/api/shifts/complete', { items: items.value })
    }
}
</script>

<template>
    <Head title="ПРИЕМКА СМЕНЫ" />
    <AdminLayout>
        <div class="max-w-5xl mx-auto space-y-8 font-mono pb-20">

            <div class="no-print flex justify-between items-center bg-[#0a0a0a] border border-white/5 p-8 rounded-[2.5rem]">
                <div>
                    <h1 class="text-3xl font-black text-white uppercase italic tracking-tighter">Shift Transfer</h1>
                    <p class="text-white/20 text-[10px] uppercase font-bold mt-1">Протокол передачи материальной ответственности</p>
                </div>
                <div class="flex gap-4">
                    <button @click="printList" class="px-6 py-3 border border-white/10 rounded-xl text-[10px] font-black uppercase hover:bg-white/5 transition-all text-white">
                        🖨️ Печать
                    </button>
                    <button @click="submitShift"
                            :class="hasDiscrepancies ? 'bg-red-600' : 'bg-[#22c55e]'"
                            class="px-8 py-3 text-black font-black uppercase rounded-xl transition-all active:scale-95 italic shadow-lg">
                        {{ hasDiscrepancies ? 'Открыть с Разногласиями' : 'Подтвердить и Открыть' }}
                    </button>
                </div>
            </div>

            <div class="bg-[#050505] border border-white/5 rounded-[3rem] overflow-hidden shadow-2xl printable-area">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-white/5 text-[10px] uppercase font-black tracking-widest text-white/40">
                    <tr>
                        <th class="p-6">Объект</th>
                        <th class="p-6 text-center">План</th>
                        <th class="p-6 text-center">Факт</th>
                        <th class="p-6 text-right no-print">Статус</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                    <tr v-for="item in items" :key="item.id"
                        :class="item.actual !== item.stock ? 'bg-red-500/10' : ''">
                        <td class="p-6">
                            <div class="font-bold text-white uppercase italic text-sm">{{ item.name }}</div>
                            <div class="text-[9px] text-white/20 uppercase">{{ item.category }}</div>
                        </td>
                        <td class="p-6 text-center font-black text-xl text-white/40">{{ item.stock }}</td>
                        <td class="p-6 text-center">
                            <input v-model.number="item.actual" type="number"
                                   class="w-20 bg-black border-2 border-white/10 rounded-lg py-2 text-center text-xl font-black text-white focus:border-cyan-500 outline-none" />
                        </td>
                        <td class="p-6 text-right no-print">
                            <span v-if="item.actual === item.stock" class="text-[#22c55e] text-[10px] font-black">OK</span>
                            <span v-else class="text-red-500 text-[10px] font-black animate-pulse">ERROR</span>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <div class="only-print footer-signatures">
                <div class="sig-row">Сдал: ____________________ (уходящий)</div>
                <div class="sig-row">Принял: ____________________ (приходящий)</div>
                <div class="time-stamp">System Time: {{ new Date().toLocaleString() }}</div>
            </div>
        </div>
    </AdminLayout>
</template>

<style scoped>
/* Исправленный CSS блок */
@media print {
    .no-print { display: none !important; }
    .only-print { display: block !important; }
    body { background: white !important; color: black !important; }
    .printable-area { border: 1px solid #000 !important; background: white !important; }
    .text-white, .text-white-40 { color: black !important; }
    input { border: 1px solid #000 !important; color: black !important; background: transparent !important; }
    th, td { border-bottom: 1px solid #ccc !important; color: black !important; }
}

.only-print { display: none; }

.footer-signatures {
    margin-top: 40px;
    font-size: 14px;
}

.sig-row {
    margin-bottom: 20px;
}

.time-stamp {
    font-size: 10px;
    margin-top: 10px;
}
</style>
