<script setup>
import { router } from '@inertiajs/vue3'
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminConfirm from '@/Components/AdminConfirm.vue'
import { useToast } from '@/Composables/useToast'
import { useAdminBarcodeScanner } from '@/Composables/useAdminBarcodeScanner'
import { useClubName } from '@/Composables/useClubName'

const clubName = useClubName()

const props = defineProps({ orders: Array })

const { success, error, info } = useToast()
const { onFulfillScan } = useAdminBarcodeScanner()

const POLL_INTERVAL = 7000
const pollTimer = ref(null)
const seenOrderIds = ref(new Set((props.orders || []).map(o => o.id)))

const localProgress = ref({})
const lastScannedOrderId = ref(null)
const hasOpenMarking = computed(() =>
    (props.orders || []).some(o => needsMarking(o) && !isMarkingComplete(o) && ['pending', 'cooking'].includes(o.status))
)

const orderLabels = {
    delivered: 'Заказ выполнен',
    cancelled: 'Заказ отменён',
}

const progressFor = (order) => {
    return localProgress.value[order.id] || order.marking_progress || []
}

const isMarkingComplete = (order) => {
    if (localProgress.value[order.id]) {
        return (localProgress.value[order.id] || []).every(r => r.remaining <= 0)
    }
    return Boolean(order.marking_complete)
}

const needsMarking = (order) => (progressFor(order) || []).length > 0

const setStatus = (id, status) => {
    const order = (props.orders || []).find(o => o.id === id)
    if (status === 'delivered' && order && needsMarking(order) && !isMarkingComplete(order)) {
        error('Отсканируйте коды маркировки перед выдачей')
        return
    }

    router.post(`/admin/orders/${id}/status`, { status }, {
        preserveScroll: true,
        onSuccess: () => {
            success(orderLabels[status] || 'Статус обновлён')
            if (status === 'delivered') {
                const next = { ...localProgress.value }
                delete next[id]
                localProgress.value = next
                if (lastScannedOrderId.value === id) lastScannedOrderId.value = null
            }
        },
        onError: (errs) => error(errs?.status || 'Не удалось обновить статус заказа'),
    })
}

const cancelTarget = ref(null)
const cancelMessage = computed(() => {
    if (!cancelTarget.value) return ''
    const pc = cancelTarget.value.pc_name ? ` (${cancelTarget.value.pc_name})` : ''
    return `Заказ #${cancelTarget.value.id}${pc} будет отменён. Действие необратимо.`
})

const confirmCancel = () => {
    if (!cancelTarget.value) return
    const id = cancelTarget.value.id
    cancelTarget.value = null
    setStatus(id, 'cancelled')
}

const refreshQueue = () => {
    router.reload({
        only: ['orders', 'admin_alerts'],
        preserveScroll: true,
        preserveState: true,
    })
}

watch(() => props.orders, (list) => {
    const ids = (list || []).map(o => o.id)
    const hasNew = ids.some(id => !seenOrderIds.value.has(id))
    seenOrderIds.value = new Set(ids)
    if (hasNew) {
        new Audio('/sounds/notification.mp3').play().catch(() => {})
        info('Новый заказ в очереди')
    }
})

let unsubscribeFulfill = null

onMounted(() => {
    pollTimer.value = setInterval(refreshQueue, POLL_INTERVAL)
    unsubscribeFulfill = onFulfillScan((data) => {
        const orderId = data.order_id
        lastScannedOrderId.value = orderId
        localProgress.value = {
            ...localProgress.value,
            [orderId]: data.marking_progress || [],
        }
    })
})

onUnmounted(() => {
    if (pollTimer.value) clearInterval(pollTimer.value)
    if (unsubscribeFulfill) unsubscribeFulfill()
})
</script>

<template>
    <AdminLayout>
        <div class="max-w-5xl mx-auto space-y-8 animate-in fade-in duration-500 font-mono text-white pb-10">

            <div class="bg-[#0a0a0a] border border-white/5 p-8 rounded-[1rem] shadow-xl flex items-center gap-4">
                <div class="w-3 h-10 bg-[#22c55e] rounded-full animate-pulse shadow-[0_0_15px_rgba(34,197,94,0.4)]"></div>
                <div>
                    <h1 class="text-3xl font-black uppercase italic text-white tracking-tighter">Очередь заказов</h1>
                    <p class="text-white/30 text-[10px] uppercase tracking-[0.4em] font-black mt-1 italic">
                        {{ hasOpenMarking ? 'Сканер глобальный — КМ списывается с любой страницы' : `${clubName} Market // Обработка` }}
                    </p>
                </div>
            </div>

            <div v-if="orders && orders.length > 0" class="space-y-4">
                <div v-for="order in orders" :key="order.id"
                     class="bg-[#050505] border rounded-[0.875rem] p-6 flex flex-col gap-4 group hover:border-[#22c55e]/40 transition-all shadow-lg relative overflow-hidden"
                     :class="lastScannedOrderId === order.id ? 'border-cyan-500 ring-1 ring-cyan-500/40' : 'border-white/5'">

                    <div class="absolute left-0 top-0 bottom-0 w-1 bg-[#22c55e]/20 group-hover:bg-[#22c55e] transition-colors"></div>

                    <div class="pl-4 flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
                        <div class="flex-1 min-w-0">
                            <div class="text-[10px] text-[#22c55e] font-black uppercase tracking-widest mb-2 italic">
                                Заказ #{{ order.id }} · Клиент: <span class="text-white">{{ order.user?.name }}</span> ({{ order.user?.phone }})
                            </div>

                            <ul v-if="order.items && order.items.length" class="space-y-1 mb-2">
                                <li v-for="(item, idx) in order.items" :key="idx"
                                    class="text-xl md:text-2xl font-black uppercase italic tracking-tight text-white flex flex-wrap items-baseline gap-x-3 gap-y-0.5">
                                    <span>{{ item.name }}</span>
                                    <span class="text-sm text-white/40 font-black not-italic tracking-widest">×{{ item.qty }}</span>
                                    <span class="text-sm text-white/30 font-mono not-italic tracking-normal">{{ Number(item.line_total || 0).toFixed(0) }} ₽</span>
                                </li>
                            </ul>
                            <div v-else class="text-2xl font-black uppercase italic tracking-tight text-white mb-1">
                                {{ order.product_name }}
                            </div>

                            <div class="flex flex-wrap items-center gap-2 mt-2">
                                <div class="inline-flex items-center gap-2 px-3 py-1 bg-white/5 border border-white/10 rounded-lg">
                                    <span class="text-[9px] text-white/40 uppercase font-black tracking-widest">Доставить на:</span>
                                    <span class="text-sm text-white font-black">{{ order.pc_name }}</span>
                                </div>
                                <div v-if="order.price > 0"
                                     class="inline-flex items-center gap-2 px-3 py-1 bg-white/5 border border-white/10 rounded-lg">
                                    <span class="text-[9px] text-white/40 uppercase font-black tracking-widest">Итого:</span>
                                    <span class="text-sm text-[#22c55e] font-black font-mono">{{ Number(order.price).toFixed(0) }} ₽</span>
                                </div>
                                <div class="inline-flex items-center px-3 py-1 rounded-lg border text-[9px] font-black uppercase tracking-widest bg-amber-500/10 border-amber-500/30 text-amber-300">
                                    {{ order.status_label || 'В очереди' }}
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-3 w-full md:w-auto">
                            <button v-if="order.status === 'cooking' || order.status === 'pending'"
                                    type="button"
                                    @click="setStatus(order.id, 'delivered')"
                                    class="flex-1 md:flex-none px-8 py-4 bg-[#22c55e] hover:bg-[#1ea34d] text-black font-black uppercase text-xs tracking-widest rounded-xl transition-all shadow-[0_0_20px_rgba(34,197,94,0.2)] active:scale-95 cursor-pointer disabled:opacity-40"
                                    :disabled="needsMarking(order) && !isMarkingComplete(order)">
                                Выполнен
                            </button>
                            <button type="button" @click="cancelTarget = order"
                                    class="flex-1 md:flex-none px-6 py-4 bg-red-500/10 border border-red-500/30 text-red-500 font-black uppercase text-xs tracking-widest rounded-xl hover:bg-red-500 hover:text-black transition-all active:scale-95 cursor-pointer">
                                Отмена
                            </button>
                        </div>
                    </div>

                    <div v-if="needsMarking(order)" class="pl-4 space-y-2">
                        <div class="text-[9px] uppercase font-black tracking-widest text-white/30">Маркировка к выдаче</div>
                        <div v-for="row in progressFor(order)" :key="row.product_id"
                             class="flex items-center justify-between px-4 py-3 rounded-xl border text-[11px] font-black uppercase tracking-widest"
                             :class="row.remaining <= 0
                                ? 'border-[#22c55e]/40 bg-[#22c55e]/10 text-[#22c55e]'
                                : 'border-amber-500/30 bg-amber-500/5 text-amber-300'">
                            <span>{{ row.name }}</span>
                            <span>{{ row.scanned }} / {{ row.required }}</span>
                        </div>
                        <p v-if="lastScannedOrderId === order.id" class="text-[10px] text-cyan-400/80 uppercase tracking-widest font-bold">
                            Последний скан привязан к этому заказу
                        </p>
                    </div>
                </div>
            </div>

            <div v-else class="text-center py-32 border-2 border-dashed border-white/5 rounded-[1.125rem] bg-[#050505]">
                <div class="text-white/10 uppercase font-black italic tracking-[0.5em] text-xl">Очередь пуста</div>
                <div class="text-[10px] text-white/20 uppercase tracking-widest mt-2 font-bold">Ожидание новых поступлений...</div>
            </div>
        </div>

        <AdminConfirm :is-open="!!cancelTarget"
                      title="Отменить заказ?"
                      :message="cancelMessage"
                      confirm-text="Да, отменить"
                      cancel-text="Не трогать"
                      tone="danger"
                      @confirm="confirmCancel"
                      @close="cancelTarget = null" />
    </AdminLayout>
</template>

<style scoped>
@reference "../../../css/app.css";
.animate-in { animation: fade-in 0.4s ease-out forwards; }
@keyframes fade-in { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
</style>
