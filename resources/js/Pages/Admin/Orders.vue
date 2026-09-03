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

const preorderGroupKey = (order) => {
    const phone = order.user?.phone || ''
    const userId = order.user_id || ''
    const pc = order.pc_name || ''
    return `pre:${phone || userId}:${pc}`
}

const mergeBlockItems = (orders) => {
    const map = new Map()
    for (const order of orders) {
        const rows = (order.items && order.items.length)
            ? order.items
            : (order.product_name ? [{
                name: order.product_name,
                qty: 1,
                line_total: Number(order.price || 0),
            }] : [])
        for (const item of rows) {
            const key = `${item.product_id || 0}:${item.name}`
            const prev = map.get(key)
            if (prev) {
                prev.qty += Number(item.qty || 1)
                prev.line_total += Number(item.line_total || 0)
            } else {
                map.set(key, {
                    product_id: item.product_id ?? null,
                    name: item.name,
                    qty: Number(item.qty || 1),
                    line_total: Number(item.line_total || 0),
                })
            }
        }
    }
    return [...map.values()]
}

const orderBlocks = computed(() => {
    const list = props.orders || []
    const blocks = []
    const preIndex = new Map()

    for (const order of list) {
        if (!order.is_preorder) {
            blocks.push({ orders: [order] })
            continue
        }
        const key = preorderGroupKey(order)
        if (preIndex.has(key)) {
            preIndex.get(key).orders.push(order)
            continue
        }
        const block = { orders: [order] }
        preIndex.set(key, block)
        blocks.push(block)
    }

    return blocks.map((block) => {
        const orders = block.orders
        const first = orders[0]
        const ids = orders.map(o => o.id)
        return {
            key: ids.length > 1 ? `g-${ids.join('-')}` : `o-${first.id}`,
            ids,
            orders,
            is_preorder: Boolean(first.is_preorder),
            user: first.user,
            pc_name: first.pc_name,
            items: mergeBlockItems(orders),
            price: orders.reduce((sum, o) => sum + Number(o.price || 0), 0),
            product_name: orders.map(o => o.product_name).filter(Boolean).join(', '),
            status: first.status,
            status_label: first.status_label,
            channel_labels: [...new Set(orders.map(o => o.channel_label).filter(Boolean))],
        }
    })
})

const orderLabels = {
    delivered: 'Заказ выполнен',
    cancelled: 'Заказ отменён',
}

const progressFor = (order) => {
    return localProgress.value[order.id] || order.marking_progress || []
}

const progressForBlock = (block) => {
    const map = new Map()
    for (const order of block.orders) {
        for (const row of progressFor(order)) {
            const key = row.product_id || row.name
            const prev = map.get(key)
            if (prev) {
                prev.scanned += Number(row.scanned || 0)
                prev.required += Number(row.required || 0)
                prev.remaining += Number(row.remaining || 0)
            } else {
                map.set(key, {
                    product_id: row.product_id,
                    name: row.name,
                    scanned: Number(row.scanned || 0),
                    required: Number(row.required || 0),
                    remaining: Number(row.remaining || 0),
                })
            }
        }
    }
    return [...map.values()]
}

const isMarkingComplete = (order) => {
    if (localProgress.value[order.id]) {
        return (localProgress.value[order.id] || []).every(r => r.remaining <= 0)
    }
    return Boolean(order.marking_complete)
}

const needsMarking = (order) => (progressFor(order) || []).length > 0

const blockNeedsMarking = (block) => progressForBlock(block).length > 0
const blockMarkingComplete = (block) => progressForBlock(block).every(r => r.remaining <= 0)

const setStatus = (ids, status) => {
    const idList = Array.isArray(ids) ? ids : [ids]
    const group = (props.orders || []).filter(o => idList.includes(o.id))
    if (status === 'delivered' && group.some(o => needsMarking(o) && !isMarkingComplete(o))) {
        error('Отсканируйте коды маркировки перед выдачей')
        return
    }

    const isBatch = idList.length > 1
    router.post(isBatch ? '/admin/orders/status' : `/admin/orders/${idList[0]}/status`, isBatch ? { ids: idList, status } : { status }, {
        preserveScroll: true,
        onSuccess: () => {
            success(idList.length > 1
                ? (status === 'delivered' ? 'Заказы выполнены' : (status === 'cancelled' ? 'Заказы отменены' : 'Статус обновлён'))
                : (orderLabels[status] || 'Статус обновлён'))
            if (status === 'delivered') {
                const next = { ...localProgress.value }
                for (const id of idList) delete next[id]
                localProgress.value = next
                if (idList.includes(lastScannedOrderId.value)) lastScannedOrderId.value = null
            }
        },
        onError: (errs) => error(errs?.status || 'Не удалось обновить статус заказа'),
    })
}

const cancelTarget = ref(null)
const cancelMessage = computed(() => {
    if (!cancelTarget.value) return ''
    const ids = cancelTarget.value.ids || [cancelTarget.value.id]
    const pc = cancelTarget.value.pc_name ? ` (${cancelTarget.value.pc_name})` : ''
    const label = ids.length > 1 ? `Заказы #${ids.join(', #')}` : `Заказ #${ids[0]}`
    const verb = ids.length > 1 ? 'будут отменены' : 'будет отменён'
    return `${label}${pc} ${verb}. Действие необратимо.`
})

const confirmCancel = () => {
    if (!cancelTarget.value) return
    const ids = cancelTarget.value.ids || [cancelTarget.value.id]
    cancelTarget.value = null
    setStatus(ids, 'cancelled')
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
                <div v-for="block in orderBlocks" :key="block.key"
                     class="bg-[#050505] border rounded-[0.875rem] p-6 flex flex-col gap-4 group hover:border-[#22c55e]/40 transition-all shadow-lg relative overflow-hidden"
                     :class="block.ids.includes(lastScannedOrderId) ? 'border-cyan-500 ring-1 ring-cyan-500/40' : 'border-white/5'">

                    <div class="absolute left-0 top-0 bottom-0 w-1 bg-[#22c55e]/20 group-hover:bg-[#22c55e] transition-colors"></div>

                    <div class="pl-4 flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
                        <div class="flex-1 min-w-0">
                            <div class="text-[10px] text-[#22c55e] font-black uppercase tracking-widest mb-2 italic">
                                {{ block.ids.length > 1 ? 'Заказы' : 'Заказ' }}
                                #{{ block.ids.join(', #') }}
                                · Клиент: <span class="text-white">{{ block.user?.name }}</span> ({{ block.user?.phone }})
                            </div>

                            <ul v-if="block.items && block.items.length" class="space-y-1 mb-2">
                                <li v-for="(item, idx) in block.items" :key="idx"
                                    class="text-xl md:text-2xl font-black uppercase italic tracking-tight text-white flex flex-wrap items-baseline gap-x-3 gap-y-0.5">
                                    <span>{{ item.name }}</span>
                                    <span class="text-sm text-white/40 font-black not-italic tracking-widest">×{{ item.qty }}</span>
                                    <span class="text-sm text-white/30 font-mono not-italic tracking-normal">{{ Number(item.line_total || 0).toFixed(0) }} ₽</span>
                                </li>
                            </ul>
                            <div v-else class="text-2xl font-black uppercase italic tracking-tight text-white mb-1">
                                {{ block.product_name }}
                            </div>

                            <div class="flex flex-wrap items-center gap-2 mt-2">
                                <div class="inline-flex items-center gap-2 px-3 py-1 bg-white/5 border border-white/10 rounded-lg">
                                    <span class="text-[9px] text-white/40 uppercase font-black tracking-widest">Доставить на:</span>
                                    <span class="text-sm text-white font-black">{{ block.pc_name }}</span>
                                </div>
                                <div v-if="block.price > 0"
                                     class="inline-flex items-center gap-2 px-3 py-1 bg-white/5 border border-white/10 rounded-lg">
                                    <span class="text-[9px] text-white/40 uppercase font-black tracking-widest">Итого:</span>
                                    <span class="text-sm text-[#22c55e] font-black font-mono">{{ Number(block.price).toFixed(0) }} ₽</span>
                                </div>
                                <div class="inline-flex items-center px-3 py-1 rounded-lg border text-[9px] font-black uppercase tracking-widest bg-amber-500/10 border-amber-500/30 text-amber-300">
                                    {{ block.status_label || 'В очереди' }}
                                </div>
                                <div v-for="label in (block.channel_labels || [])" :key="label"
                                     class="inline-flex items-center px-3 py-1 rounded-lg border text-[9px] font-black uppercase tracking-widest bg-white/5 border-white/15 text-white/70">
                                    {{ label }}
                                </div>
                                <div v-if="block.is_preorder"
                                     class="inline-flex items-center px-3 py-1 rounded-lg border text-[9px] font-black uppercase tracking-widest bg-[#eab308]/15 border-[#eab308]/40 text-[#eab308]">
                                    Предзаказ
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-3 w-full md:w-auto">
                            <button v-if="block.status === 'cooking' || block.status === 'pending'"
                                    type="button"
                                    @click="setStatus(block.ids, 'delivered')"
                                    class="flex-1 md:flex-none px-8 py-4 bg-[#22c55e] hover:bg-[#1ea34d] text-black font-black uppercase text-xs tracking-widest rounded-xl transition-all shadow-[0_0_20px_rgba(34,197,94,0.2)] active:scale-95 cursor-pointer disabled:opacity-40"
                                    :disabled="blockNeedsMarking(block) && !blockMarkingComplete(block)">
                                Выполнен
                            </button>
                            <button type="button" @click="cancelTarget = block"
                                    class="flex-1 md:flex-none px-6 py-4 bg-red-500/10 border border-red-500/30 text-red-500 font-black uppercase text-xs tracking-widest rounded-xl hover:bg-red-500 hover:text-black transition-all active:scale-95 cursor-pointer">
                                Отмена
                            </button>
                        </div>
                    </div>

                    <div v-if="blockNeedsMarking(block)" class="pl-4 space-y-2">
                        <div class="text-[9px] uppercase font-black tracking-widest text-white/30">Маркировка к выдаче</div>
                        <div v-for="row in progressForBlock(block)" :key="row.product_id || row.name"
                             class="flex items-center justify-between px-4 py-3 rounded-xl border text-[11px] font-black uppercase tracking-widest"
                             :class="row.remaining <= 0
                                ? 'border-[#22c55e]/40 bg-[#22c55e]/10 text-[#22c55e]'
                                : 'border-amber-500/30 bg-amber-500/5 text-amber-300'">
                            <span>{{ row.name }}</span>
                            <span>{{ row.scanned }} / {{ row.required }}</span>
                        </div>
                        <p v-if="block.ids.includes(lastScannedOrderId)" class="text-[10px] text-cyan-400/80 uppercase tracking-widest font-bold">
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
                      :title="(cancelTarget?.ids?.length || 0) > 1 ? 'Отменить заказы?' : 'Отменить заказ?'"
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
