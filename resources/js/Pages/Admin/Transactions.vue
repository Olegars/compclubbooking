<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import AdminLayout from '@/Layouts/AdminLayout.vue'

type TxUser = {
    id: number
    name: string | null
    phone: string | null
    email: string | null
}

type TxRow = {
    id: number
    type: string
    source: string | null
    amount: number
    description: string | null
    send_receipt: boolean
    fiscal_mode: string | null
    fiscal_status: string | null
    fiscal_receipt_url: string | null
    fiscal_error: string | null
    fiscal_at: string | null
    created_at: string | null
    user: TxUser | null
    is_stub_receipt?: boolean
    can_print: boolean
}

const props = defineProps<{
    transactions: {
        data: TxRow[]
        links: any[]
        current_page: number
        last_page: number
    }
    filters: {
        phone: string
        type: string
        fiscal_status: string
    }
}>()

const phone = ref(props.filters.phone || '')
const type = ref(props.filters.type || '')
const fiscalStatus = ref(props.filters.fiscal_status || '')
const printBusyId = ref<number | null>(null)
const printError = ref('')

watch(() => props.filters, (f) => {
    phone.value = f.phone || ''
    type.value = f.type || ''
    fiscalStatus.value = f.fiscal_status || ''
}, { deep: true })

const applyFilters = () => {
    router.get('/admin/transactions', {
        phone: phone.value || undefined,
        type: type.value || undefined,
        fiscal_status: fiscalStatus.value || undefined,
    }, {
        preserveState: true,
        replace: true,
    })
}

const typeLabel = (t: string) => ({
    deposit: 'Пополнение',
    booking: 'Бронь',
    booking_upgrade: 'Доплата брони',
    purchase: 'Магазин',
    refund: 'Возврат',
} as Record<string, string>)[t] || t

const statusClass = (s: string | null) => {
    if (s === 'success') return 'text-[#22c55e]'
    if (s === 'error') return 'text-red-400'
    if (s === 'pending' || s === 'deferred') return 'text-amber-400'
    if (s === 'void') return 'text-white/25'
    return 'text-white/30'
}

const printCopy = async (tx: TxRow) => {
    if (!tx.can_print || printBusyId.value) return
    printBusyId.value = tx.id
    printError.value = ''
    try {
        const { data } = await axios.get(`/admin/transactions/${tx.id}/print-copy`)
        const w = window.open('', '_blank', 'width=420,height=720')
        if (!w) {
            printError.value = 'Разрешите всплывающие окна для печати'
            return
        }
        const amount = Number(data.amount || 0)
        const amountText = `${amount > 0 ? '+' : ''}${amount.toFixed(2)} ₽`
        w.document.write(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>КОПИЯ ЧЕКА #${data.id}</title>
<style>
  body{font-family:ui-monospace,Consolas,monospace;background:#fff;color:#111;padding:24px;max-width:360px;margin:0 auto}
  h1{font-size:18px;letter-spacing:.12em;text-align:center;margin:0 0 12px}
  .muted{color:#666;font-size:11px;text-align:center}
  .row{display:flex;justify-content:space-between;gap:12px;margin:8px 0;font-size:13px}
  .qr{display:block;margin:18px auto;width:220px;height:220px}
  .box{border:1px dashed #999;padding:12px;margin-top:16px}
  @media print{button{display:none}}
</style></head><body>
<h1>КОПИЯ ЧЕКА</h1>
<div class="muted">Не является повторной фискализацией · REACTOR</div>
<div class="box">
  <div class="row"><span>Транзакция</span><strong>#${data.id}</strong></div>
  <div class="row"><span>Гость</span><strong>${data.user?.name || '—'}</strong></div>
  <div class="row"><span>Телефон</span><strong>${data.user?.phone || '—'}</strong></div>
  <div class="row"><span>Описание</span><strong>${data.description || '—'}</strong></div>
  <div class="row"><span>Сумма</span><strong>${amountText}</strong></div>
  <div class="row"><span>Режим</span><strong>${data.fiscal_mode || '—'}</strong></div>
</div>
<img class="qr" src="${data.qr_image_url}" alt="QR чека" />
<div class="muted" style="word-break:break-all;margin-top:8px">${data.fiscal_receipt_url}</div>
<button onclick="window.print()" style="margin:20px auto;display:block;padding:10px 18px;font-weight:700">Печать</button>
<script>window.onload=function(){setTimeout(function(){window.print()},400)}<\/script>
</body></html>`)
        w.document.close()
    } catch (e: any) {
        printError.value = e?.response?.data?.message || 'Не удалось подготовить копию чека'
    } finally {
        printBusyId.value = null
    }
}

const rows = computed(() => props.transactions?.data || [])
</script>

<template>
    <AdminLayout>
        <div class="max-w-7xl mx-auto space-y-8 animate-in fade-in duration-500">
            <div class="bg-[#0a0a0a] border border-white/5 p-8 rounded-[1rem] shadow-xl">
                <h1 class="text-3xl font-black uppercase italic text-white tracking-tighter">
                    Транзакции <span class="text-[#22c55e]">/ чеки</span>
                </h1>
                <p class="text-white/30 text-[10px] uppercase tracking-[0.35em] font-black mt-2 italic">
                    Поиск по телефону · печать копии без повторной фискализации
                </p>
            </div>

            <div class="bg-[#0a0a0a] border border-white/5 rounded-[1rem] p-6 shadow-xl space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <input
                        v-model="phone"
                        type="text"
                        placeholder="Телефон или имя гостя"
                        class="md:col-span-2 bg-black border border-white/10 focus:border-[#22c55e]/50 rounded-xl px-5 py-4 text-white font-mono outline-none text-sm placeholder:text-white/20"
                        @keyup.enter="applyFilters"
                    />
                    <select v-model="type" class="bg-black border border-white/10 rounded-xl px-4 py-4 text-[11px] font-black uppercase tracking-widest text-white/70 outline-none">
                        <option value="">Все типы</option>
                        <option value="deposit">Пополнение</option>
                        <option value="booking">Бронь</option>
                        <option value="purchase">Магазин</option>
                        <option value="refund">Возврат</option>
                    </select>
                    <select v-model="fiscalStatus" class="bg-black border border-white/10 rounded-xl px-4 py-4 text-[11px] font-black uppercase tracking-widest text-white/70 outline-none">
                        <option value="">Статус чека</option>
                        <option value="success">success</option>
                        <option value="pending">pending</option>
                        <option value="deferred">deferred</option>
                        <option value="void">void</option>
                        <option value="error">error</option>
                        <option value="skipped">skipped</option>
                    </select>
                </div>
                <button
                    type="button"
                    class="px-6 py-3 rounded-xl bg-[#22c55e] text-black text-[11px] font-black uppercase tracking-widest italic"
                    @click="applyFilters"
                >
                    Найти
                </button>
                <div v-if="printError" class="text-red-400 text-[11px] font-black uppercase tracking-widest">{{ printError }}</div>
            </div>

            <div class="bg-[#0a0a0a] border border-white/5 rounded-[1rem] p-6 shadow-xl overflow-x-auto">
                <table class="w-full text-left border-collapse min-w-[960px]">
                    <thead>
                        <tr class="border-b border-white/10">
                            <th class="py-3 px-3 text-[10px] text-white/30 uppercase font-black tracking-widest">Время</th>
                            <th class="py-3 px-3 text-[10px] text-white/30 uppercase font-black tracking-widest">Телефон</th>
                            <th class="py-3 px-3 text-[10px] text-white/30 uppercase font-black tracking-widest">Гость</th>
                            <th class="py-3 px-3 text-[10px] text-white/30 uppercase font-black tracking-widest">Тип</th>
                            <th class="py-3 px-3 text-[10px] text-white/30 uppercase font-black tracking-widest">Сумма</th>
                            <th class="py-3 px-3 text-[10px] text-white/30 uppercase font-black tracking-widest">Чек</th>
                            <th class="py-3 px-3 text-[10px] text-white/30 uppercase font-black tracking-widest">ОФД</th>
                            <th class="py-3 px-3 text-[10px] text-white/30 uppercase font-black tracking-widest"></th>
                        </tr>
                    </thead>
                    <tbody class="font-mono text-sm">
                        <tr v-if="rows.length === 0">
                            <td colspan="8" class="py-16 text-center text-white/20 uppercase font-black tracking-[0.3em] italic">
                                Транзакций не найдено
                            </td>
                        </tr>
                        <tr
                            v-for="tx in rows"
                            :key="tx.id"
                            class="border-b border-white/5 hover:bg-white/[0.02]"
                        >
                            <td class="py-4 px-3 text-white/50 text-xs whitespace-nowrap">{{ tx.created_at }}</td>
                            <td class="py-4 px-3 text-[#22c55e] font-black whitespace-nowrap">{{ tx.user?.phone || '—' }}</td>
                            <td class="py-4 px-3 text-white/80">{{ tx.user?.name || '—' }}</td>
                            <td class="py-4 px-3">
                                <div class="text-[11px] font-black uppercase italic tracking-tight">{{ typeLabel(tx.type) }}</div>
                                <div class="text-[10px] text-white/25 mt-1 max-w-[220px] truncate">{{ tx.description }}</div>
                            </td>
                            <td class="py-4 px-3 font-black italic whitespace-nowrap"
                                :class="tx.amount > 0 ? 'text-[#22c55e]' : 'text-white/50'">
                                {{ tx.amount > 0 ? '+' : '' }}{{ tx.amount }} ₽
                            </td>
                            <td class="py-4 px-3 text-[11px] uppercase font-black tracking-widest"
                                :class="statusClass(tx.fiscal_status)">
                                {{ tx.fiscal_status || '—' }}
                                <div v-if="tx.send_receipt" class="text-[9px] text-white/25 normal-case tracking-normal mt-1">+Email/SMS</div>
                            </td>
                            <td class="py-4 px-3">
                                <a
                                    v-if="tx.fiscal_receipt_url"
                                    :href="tx.fiscal_receipt_url"
                                    target="_blank"
                                    rel="noopener"
                                    class="text-[10px] uppercase font-black tracking-widest hover:underline"
                                    :class="tx.is_stub_receipt ? 'text-amber-400' : 'text-cyan-400'"
                                >{{ tx.is_stub_receipt ? 'Демо' : 'Открыть' }}</a>
                                <span v-else class="text-white/20 text-[10px]">—</span>
                            </td>
                            <td class="py-4 px-3 text-right">
                                <button
                                    type="button"
                                    class="px-4 py-2 rounded-xl border text-[10px] font-black uppercase tracking-widest transition-all disabled:opacity-30"
                                    :class="tx.can_print
                                        ? 'border-[#22c55e]/40 text-[#22c55e] hover:bg-[#22c55e]/10'
                                        : 'border-white/10 text-white/20'"
                                    :disabled="!tx.can_print || printBusyId === tx.id"
                                    @click="printCopy(tx)"
                                >
                                    {{ printBusyId === tx.id ? '…' : 'Напечатать' }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div v-if="transactions.last_page > 1" class="flex flex-wrap gap-2 mt-6">
                    <button
                        v-for="link in transactions.links"
                        :key="link.label + String(link.url)"
                        type="button"
                        class="px-3 py-2 rounded-lg border text-[10px] font-black uppercase tracking-widest"
                        :class="link.active
                            ? 'border-[#22c55e] text-[#22c55e]'
                            : 'border-white/10 text-white/40 hover:text-white'"
                        :disabled="!link.url"
                        v-html="link.label"
                        @click="link.url && router.get(link.url, {}, { preserveState: true })"
                    />
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
