<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'

type Row = {
    id: number
    name: string
    role: string
    open_xp: number
    month_rub: number
    safe_rub: number
    burned: boolean
}

const props = defineProps<{
    board: {
        rate: number
        bar_target_rub: number
        month_label: string
        quarter_label: string
        days_until_open: number
        previous_month: string
        previous_quarter: string
        rows: Row[]
    }
}>()

const rate = ref(String(props.board.rate))
const target = ref(String(props.board.bar_target_rub))
const adminId = ref(props.board.rows[0]?.id ? String(props.board.rows[0].id) : '')
const amount = ref('50')
const reason = ref('')
const busy = ref(false)

const money = (value: number) => Number(value || 0).toLocaleString('ru-RU', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
}) + ' ₽'

const post = (url: string, data: Record<string, string | number>) => {
    busy.value = true
    router.post(url, data, {
        preserveScroll: true,
        onFinish: () => {
            busy.value = false
        },
    })
}
</script>

<template>
    <section class="bg-[#050505] border border-[#22c55e]/20 rounded-[1.125rem] p-8 space-y-6">
        <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4">
            <div>
                <div class="text-[10px] text-[#22c55e] uppercase font-black tracking-widest">Баллы эффективности</div>
                <h2 class="text-xl font-black uppercase italic text-white mt-2">Рейтинг смены</h2>
                <p class="text-white/30 text-[10px] uppercase tracking-widest mt-2">
                    {{ board.month_label }} · {{ board.quarter_label }} · до вскрытия фонда {{ board.days_until_open }} дн.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="button" :disabled="busy" class="px-4 py-3 border border-white/15 text-white/70 rounded-xl text-[10px] font-black uppercase tracking-widest disabled:opacity-40"
                        @click="post('/admin/staff/bonus/close-month', { period: board.previous_month })">
                    Закрыть {{ board.previous_month }}
                </button>
                <button type="button" :disabled="busy" class="px-4 py-3 border border-white/15 text-white/70 rounded-xl text-[10px] font-black uppercase tracking-widest disabled:opacity-40"
                        @click="post('/admin/staff/bonus/close-quarter', { period: board.previous_quarter })">
                    Фонд {{ board.previous_quarter }}
                </button>
            </div>
        </div>

        <form class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end" @submit.prevent="post('/admin/staff/bonus/settings', { xp_to_rub_rate: rate, bar_target_rub: target })">
            <label class="text-[10px] uppercase font-black tracking-widest text-white/40">
                Курс, ₽ за 1 XP
                <input v-model="rate" type="number" min="0.01" step="0.01" class="mt-2 w-full bg-black/40 border border-white/10 rounded-2xl px-4 py-3 text-sm text-white outline-none">
            </label>
            <label class="text-[10px] uppercase font-black tracking-widest text-white/40">
                План бара за смену, ₽
                <input v-model="target" type="number" min="0" step="1" class="mt-2 w-full bg-black/40 border border-white/10 rounded-2xl px-4 py-3 text-sm text-white outline-none">
            </label>
            <button type="submit" :disabled="busy" class="px-4 py-3 bg-[#22c55e] text-black rounded-2xl text-[10px] font-black uppercase tracking-widest disabled:opacity-40">
                Сохранить
            </button>
        </form>

        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                <tr class="border-b border-white/10 text-[10px] uppercase tracking-widest text-white/30">
                    <th class="py-3 pr-4">Сотрудник</th>
                    <th class="py-3 pr-4 text-right">XP месяца</th>
                    <th class="py-3 pr-4 text-right">К премии 70%</th>
                    <th class="py-3 text-right">Фонд</th>
                </tr>
                </thead>
                <tbody>
                <tr v-for="row in board.rows" :key="row.id" class="border-b border-white/5 text-sm">
                    <td class="py-3 pr-4 text-white font-bold">{{ row.name }}</td>
                    <td class="py-3 pr-4 text-right text-white/80">{{ row.open_xp }}</td>
                    <td class="py-3 pr-4 text-right text-[#22c55e]">{{ money(row.month_rub) }}</td>
                    <td class="py-3 text-right" :class="row.burned ? 'text-amber-300' : 'text-white/80'">
                        {{ row.burned ? 'Аннулирован по ЛНА' : money(row.safe_rub) }}
                    </td>
                </tr>
                <tr v-if="board.rows.length === 0">
                    <td colspan="4" class="py-6 text-white/30 text-sm">Нет админов зала.</td>
                </tr>
                </tbody>
            </table>
        </div>

        <form class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end" @submit.prevent="post('/admin/staff/bonus/adjust', { admin_id: adminId, amount_xp: amount, reason })">
            <label class="text-[10px] uppercase font-black tracking-widest text-white/40">
                Сотрудник
                <select v-model="adminId" class="mt-2 w-full bg-black/40 border border-white/10 rounded-2xl px-4 py-3 text-sm text-white outline-none">
                    <option v-for="row in board.rows" :key="row.id" :value="String(row.id)">{{ row.name }}</option>
                </select>
            </label>
            <label class="text-[10px] uppercase font-black tracking-widest text-white/40">
                XP
                <input v-model="amount" type="number" min="-500" max="500" step="1" class="mt-2 w-full bg-black/40 border border-white/10 rounded-2xl px-4 py-3 text-sm text-white outline-none">
            </label>
            <label class="text-[10px] uppercase font-black tracking-widest text-white/40 md:col-span-1">
                Причина
                <input v-model="reason" type="text" maxlength="255" placeholder="Турнир, акт, помощь залу" class="mt-2 w-full bg-black/40 border border-white/10 rounded-2xl px-4 py-3 text-sm text-white outline-none">
            </label>
            <button type="submit" :disabled="busy || !adminId" class="px-4 py-3 border border-[#22c55e]/40 text-[#22c55e] rounded-2xl text-[10px] font-black uppercase tracking-widest disabled:opacity-40">
                Провести
            </button>
        </form>
    </section>
</template>
