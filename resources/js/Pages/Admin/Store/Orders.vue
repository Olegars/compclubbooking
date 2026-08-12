<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

type OrderLine = {
    type: string | null
    store_component_id: number | null
    qty: number
}

const props = defineProps<{
    orders: any[]
    clients: any[]
    components: any[]
    componentTypes: Record<string, string>
    assemblers: any[]
    statuses: string[]
    filters: { status?: string | null }
    canCreate: boolean
    canCancel: boolean
    canAssign: boolean
}>()

const money = (v: any) => Number(v || 0).toLocaleString('ru-RU') + ' ₽'
const statusLabel: Record<string, string> = {
    new: 'Новый',
    assembling: 'Сборка',
    ready: 'Готов',
    issued: 'Выдан',
    cancelled: 'Отменён',
    returned: 'Возврат',
}

const showCreate = ref(false)
const submitting = ref(false)
const form = useForm({
    store_client_id: null as number | null,
    assignee_id: null as number | null,
    notes: '',
    items: [{ type: null, store_component_id: null, qty: 1 }] as OrderLine[],
})

const emptyLine = (): OrderLine => ({ type: null, store_component_id: null, qty: 1 })

const addLine = () => form.items.push(emptyLine())
const removeLine = (i: number) => { if (form.items.length > 1) form.items.splice(i, 1) }

const selectedIds = computed(() =>
    new Set(form.items.map(l => l.store_component_id).filter(Boolean) as number[])
)

const componentsForLine = (line: OrderLine) => {
    const list = props.components || []
    return list.filter(c => {
        if (line.type && c.type !== line.type) return false
        // уже выбранные в других строках скрываем
        if (c.id !== line.store_component_id && selectedIds.value.has(c.id)) return false
        return true
    })
}

const onTypeChange = (line: OrderLine) => {
    line.store_component_id = null
}

const serialLabel = (c: any) => {
    if (Array.isArray(c.serials) && c.serials.length) return c.serials.join(' · ')
    return c.warranty_number || ''
}

const create = () => {
    const items = form.items
        .filter(l => l.store_component_id)
        .map(l => ({ store_component_id: l.store_component_id, qty: 1 }))
    if (!items.length) return

    submitting.value = true
    router.post('/admin/store/orders', {
        store_client_id: form.store_client_id,
        assignee_id: form.assignee_id,
        notes: form.notes,
        items,
    }, {
        onFinish: () => { submitting.value = false },
        onSuccess: () => {
            showCreate.value = false
            form.reset()
            form.items = [emptyLine()]
        },
    })
}

const setStatus = (orderId: number, status: string) => {
    router.post(`/admin/store/orders/${orderId}/status`, { status }, { preserveScroll: true })
}

const setAssignee = (orderId: number, assigneeId: string) => {
    router.post(`/admin/store/orders/${orderId}/assign`, {
        assignee_id: assigneeId ? Number(assigneeId) : null,
    }, { preserveScroll: true })
}

const removeItem = (orderId: number, itemId: number) => {
    if (!confirm('Удалить позицию из заказа? Комплектующая вернётся на склад.')) return
    router.delete(`/admin/store/orders/${orderId}/items/${itemId}`, { preserveScroll: true })
}

const filterStatus = computed({
    get: () => props.filters?.status || '',
    set: (v: string) => router.get('/admin/store/orders', v ? { status: v } : {}, { preserveState: true })
})
</script>

<template>
    <Head title="REACTOR | Заказы магазина" />
    <AdminLayout>
        <div class="max-w-7xl mx-auto space-y-8 pb-20 px-4 font-mono">
            <div class="flex flex-wrap justify-between items-end gap-4 border-b border-white/10 pb-6">
                <div>
                    <h1 class="text-3xl font-black uppercase italic tracking-tighter">Store <span class="text-amber-400">Orders</span></h1>
                    <p class="text-white/20 text-[10px] uppercase tracking-[0.4em] font-black mt-2 italic">Сборки и продажи ПК</p>
                </div>
                <div class="flex gap-3 items-center">
                    <select v-model="filterStatus" class="bg-black border border-white/10 rounded-xl px-4 py-3 text-[10px] uppercase font-black">
                        <option value="">Все статусы</option>
                        <option v-for="s in statuses" :key="s" :value="s">{{ statusLabel[s] || s }}</option>
                    </select>
                    <button v-if="canCreate" @click="showCreate = true"
                            class="px-6 py-4 bg-amber-500 text-black font-black uppercase tracking-widest text-[10px] rounded-2xl">
                        + Заказ
                    </button>
                </div>
            </div>

            <div class="space-y-4">
                <div v-for="o in orders" :key="o.id" class="border border-white/5 bg-[#080808] rounded-2xl p-6 space-y-4">
                    <div class="flex flex-wrap justify-between gap-3">
                        <div>
                            <div class="font-black uppercase">#{{ o.id }} · {{ statusLabel[o.status] || o.status }}</div>
                            <div class="text-[10px] text-white/30 uppercase tracking-widest mt-1">
                                {{ o.client?.name || 'Без клиента' }}
                                <span v-if="o.client?.phone"> · {{ o.client.phone }}</span>
                                <span v-if="o.assignee"> · сборщик {{ o.assignee.name }}</span>
                            </div>
                        </div>
                        <div class="font-black text-amber-400">{{ money(o.total) }}</div>
                    </div>
                    <div class="text-xs text-white/40 space-y-1">
                        <div v-for="item in o.items" :key="item.id" class="flex items-center gap-2">
                            <span class="flex-1">{{ item.name }} × {{ item.qty }} — {{ money(item.price) }}</span>
                            <button v-if="canCreate && !['cancelled','returned'].includes(o.status)"
                                    type="button"
                                    class="text-red-400/80 hover:text-red-400 text-[10px] uppercase font-black"
                                    title="Удалить из заказа → на склад"
                                    @click="removeItem(o.id, item.id)">×</button>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2 items-center">
                        <button v-for="s in ['assembling','ready','issued']" :key="s"
                                @click="setStatus(o.id, s)"
                                class="px-3 py-2 rounded-xl border border-white/10 text-[10px] uppercase font-black text-white/50 hover:text-amber-400">
                            {{ statusLabel[s] }}
                        </button>
                        <button v-if="canCancel" @click="setStatus(o.id, 'cancelled')"
                                class="px-3 py-2 rounded-xl border border-red-500/20 text-[10px] uppercase font-black text-red-400">Отмена</button>
                        <button v-if="canCancel" @click="setStatus(o.id, 'returned')"
                                class="px-3 py-2 rounded-xl border border-red-500/20 text-[10px] uppercase font-black text-red-400">Возврат</button>
                        <select v-if="canAssign"
                                class="ml-auto bg-black border border-white/10 rounded-xl px-3 py-2 text-[10px] uppercase font-black"
                                :value="o.assignee_id || ''"
                                @change="setAssignee(o.id, ($event.target as HTMLSelectElement).value)">
                            <option value="">Сборщик</option>
                            <option v-for="a in assemblers" :key="a.id" :value="a.id">{{ a.name }}</option>
                        </select>
                    </div>
                </div>
                <div v-if="!orders.length" class="text-white/30 text-sm py-10 text-center">Заказов нет</div>
            </div>
        </div>

        <div v-if="showCreate" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 p-4" @click.self="showCreate = false">
            <form class="bg-[#0a0a0a] border border-white/10 rounded-3xl p-8 w-full max-w-2xl space-y-4" @submit.prevent="create">
                <h3 class="font-black uppercase italic text-xl">Новый заказ</h3>
                <select v-model="form.store_client_id" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm">
                    <option :value="null">Без клиента</option>
                    <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }} · {{ c.phone }}</option>
                </select>
                <select v-model="form.assignee_id" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm">
                    <option :value="null">Сборщик не назначен</option>
                    <option v-for="a in assemblers" :key="a.id" :value="a.id">{{ a.name }}</option>
                </select>

                <div class="text-[10px] uppercase font-black text-white/30 tracking-widest">Со склада (только «на складе»)</div>
                <div v-for="(line, i) in form.items" :key="i" class="grid grid-cols-[140px_1fr_40px] gap-2">
                    <select v-model="line.type" class="bg-black border border-white/10 rounded-xl px-3 py-2 text-sm" @change="onTypeChange(line)">
                        <option :value="null">Тип</option>
                        <option v-for="(label, key) in componentTypes" :key="key" :value="key">{{ label }}</option>
                    </select>
                    <select v-model="line.store_component_id" class="bg-black border border-white/10 rounded-xl px-3 py-2 text-sm" required>
                        <option :value="null" disabled>Комплектующая</option>
                        <option v-for="c in componentsForLine(line)" :key="c.id" :value="c.id">
                            {{ c.name }}{{ serialLabel(c) ? ' · S/N ' + serialLabel(c) : '' }} · {{ money(c.purchase_price) }}
                        </option>
                    </select>
                    <button type="button" class="text-red-400" @click="removeLine(i)">×</button>
                </div>
                <p v-if="!(components || []).length" class="text-xs text-red-400/80">На складе нет позиций со статусом «на складе».</p>
                <button type="button" class="text-[10px] uppercase font-black text-amber-400" @click="addLine">+ позиция</button>

                <textarea v-model="form.notes" placeholder="Заметки" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" rows="2" />
                <div class="flex gap-3 justify-end">
                    <button type="button" class="px-4 py-3 text-[10px] uppercase font-black text-white/40" @click="showCreate = false">Отмена</button>
                    <button class="px-6 py-3 bg-amber-500 text-black text-[10px] uppercase font-black rounded-xl" :disabled="submitting">Создать</button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
