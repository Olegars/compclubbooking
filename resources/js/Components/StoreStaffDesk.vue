<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'

type DeskOrder = {
    id: number
    status: string
    total: number
    notes: string | null
    client: string | null
    assignee: string | null
    assignee_id: number | null
    mine: boolean
    can_take: boolean
    created_at: string | null
}

type DeskItem = {
    id: number
    title?: string | null
    product_name?: string | null
    serial?: string | null
    serial_number?: string | null
    client: string | null
    assembler?: string | null
    status: string
    status_label?: string
    sale_total?: number
}

type DeskLink = { href: string; label: string; hint: string }

type TeamRow = { id: number; name: string; role: string; role_label: string }

export type StoreDesk = {
    role: string
    role_label: string
    headline: string
    permissions: {
        can_manage: boolean
        can_assign: boolean
        can_cancel: boolean
        can_close_warranty: boolean
        can_take_orders: boolean
    }
    counts: {
        orders_new: number
        orders_assembling: number
        orders_ready: number
        orders_mine: number
        pcs_assembling: number
        warranties_claimed: number
        estimates_active: number
        avito_unread: number
    }
    orders: DeskOrder[]
    pcs: DeskItem[]
    warranties: DeskItem[]
    estimates: DeskItem[]
    team: TeamRow[]
    links: DeskLink[]
}

const props = defineProps<{ desk: StoreDesk }>()

const takingId = ref<number | null>(null)

const money = (value: number | null | undefined) =>
    Number(value ?? 0).toLocaleString('ru-RU', { maximumFractionDigits: 0 }) + ' ₽'

const orderStatus: Record<string, string> = {
    new: 'Новый',
    assembling: 'Сборка',
    ready: 'Готов',
    issued: 'Выдан',
}

const tiles = computed(() => {
    const c = props.desk.counts
    const role = props.desk.role
    const items = [
        { key: 'new', label: 'Новые заказы', value: c.orders_new },
        { key: 'build', label: role === 'assembler' ? 'Мои в работе' : 'На сборке', value: role === 'assembler' ? c.orders_mine : c.orders_assembling },
        { key: 'ready', label: 'К выдаче', value: c.orders_ready },
        { key: 'claim', label: 'Гарантии', value: c.warranties_claimed },
    ]
    if (props.desk.permissions.can_manage) {
        items.splice(1, 0, { key: 'est', label: 'Сметы', value: c.estimates_active })
    }
    return items
})

const takeOrder = (order: DeskOrder) => {
    if (takingId.value || !order.can_take) return
    takingId.value = order.id
    router.post(`/admin/store/orders/${order.id}/status`, { status: 'assembling' }, {
        preserveScroll: true,
        onFinish: () => { takingId.value = null },
    })
}
</script>

<template>
    <div class="space-y-8">
        <div class="grid grid-cols-2 xl:grid-cols-5 gap-4">
            <div v-for="tile in tiles" :key="tile.key"
                 class="bg-[#050505] border border-white/5 p-6 rounded-[0.875rem]">
                <div class="text-[10px] text-white/30 uppercase font-black tracking-widest">{{ tile.label }}</div>
                <div class="text-3xl font-black text-white tracking-tighter mt-2">{{ tile.value }}</div>
            </div>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <Link v-for="link in desk.links" :key="link.href" :href="link.href"
                  class="bg-[#0a0a0a] border border-white/10 hover:border-amber-500/40 rounded-2xl px-5 py-4 transition-colors">
                <div class="text-white text-xs font-black uppercase tracking-widest">{{ link.label }}</div>
                <div class="text-white/35 text-[10px] uppercase font-bold mt-1">{{ link.hint }}</div>
            </Link>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
            <div class="bg-[#0a0a0a] border border-white/5 rounded-[1.125rem] overflow-hidden">
                <div class="p-6 border-b border-white/10 flex items-center justify-between">
                    <h2 class="text-sm font-black uppercase italic tracking-widest text-white">Очередь заказов</h2>
                    <Link href="/admin/store/orders" class="text-[10px] uppercase font-black tracking-widest text-amber-400">Все</Link>
                </div>
                <div v-if="desk.orders.length === 0" class="px-6 py-12 text-center text-white/30 text-[10px] uppercase tracking-widest font-black">
                    Открытых заказов нет
                </div>
                <div v-else class="divide-y divide-white/5">
                    <div v-for="order in desk.orders" :key="order.id" class="px-6 py-5 flex flex-col md:flex-row md:items-center gap-3">
                        <div class="flex-1 min-w-0">
                            <div class="text-white text-sm font-black uppercase italic">
                                #{{ order.id }}
                                <span class="text-white/30 not-italic">· {{ orderStatus[order.status] || order.status }}</span>
                            </div>
                            <div class="text-white/50 text-xs mt-1 truncate">
                                {{ order.client || 'Без клиента' }}
                                <span v-if="order.assignee"> · {{ order.assignee }}</span>
                            </div>
                        </div>
                        <div class="text-white/70 text-xs font-black">{{ money(order.total) }}</div>
                        <button v-if="order.can_take" type="button" :disabled="takingId === order.id"
                                class="px-5 py-3 bg-amber-500 hover:bg-amber-400 text-black rounded-xl text-[10px] font-black uppercase tracking-widest disabled:opacity-40"
                                @click="takeOrder(order)">
                            Взять в работу
                        </button>
                        <Link v-else :href="`/admin/store/orders`"
                              class="px-5 py-3 border border-white/15 text-white/70 hover:text-white rounded-xl text-[10px] font-black uppercase tracking-widest">
                            Открыть
                        </Link>
                    </div>
                </div>
            </div>

            <div class="bg-[#0a0a0a] border border-white/5 rounded-[1.125rem] overflow-hidden">
                <div class="p-6 border-b border-white/10 flex items-center justify-between">
                    <h2 class="text-sm font-black uppercase italic tracking-widest text-white">Сборки</h2>
                    <Link href="/admin/store/built-pcs" class="text-[10px] uppercase font-black tracking-widest text-amber-400">Все</Link>
                </div>
                <div v-if="desk.pcs.length === 0" class="px-6 py-12 text-center text-white/30 text-[10px] uppercase tracking-widest font-black">
                    Нет ПК на сборке
                </div>
                <div v-else class="divide-y divide-white/5">
                    <Link v-for="pc in desk.pcs" :key="pc.id" href="/admin/store/built-pcs"
                          class="px-6 py-5 block hover:bg-white/[0.02]">
                        <div class="text-white text-sm font-black uppercase italic truncate">{{ pc.title || ('Сборка #' + pc.id) }}</div>
                        <div class="text-white/50 text-xs mt-1">
                            {{ pc.client || 'Без клиента' }}
                            <span v-if="pc.assembler"> · {{ pc.assembler }}</span>
                            <span v-if="pc.serial_number"> · {{ pc.serial_number }}</span>
                        </div>
                    </Link>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
            <div v-if="desk.estimates.length" class="bg-[#0a0a0a] border border-white/5 rounded-[1.125rem] overflow-hidden">
                <div class="p-6 border-b border-white/10 flex items-center justify-between">
                    <h2 class="text-sm font-black uppercase italic tracking-widest text-white">Сметы</h2>
                    <Link href="/admin/store/estimates" class="text-[10px] uppercase font-black tracking-widest text-amber-400">Все</Link>
                </div>
                <div class="divide-y divide-white/5">
                    <Link v-for="row in desk.estimates" :key="row.id" href="/admin/store/estimates"
                          class="px-6 py-5 flex items-center gap-3 hover:bg-white/[0.02]">
                        <div class="flex-1 min-w-0">
                            <div class="text-white text-sm font-black uppercase italic truncate">{{ row.title || ('Смета #' + row.id) }}</div>
                            <div class="text-white/50 text-xs mt-1">{{ row.client || 'Без клиента' }} · {{ row.status_label }}</div>
                        </div>
                        <div class="text-white/70 text-xs font-black">{{ money(row.sale_total) }}</div>
                    </Link>
                </div>
            </div>

            <div v-if="desk.warranties.length" class="bg-[#0a0a0a] border border-white/5 rounded-[1.125rem] overflow-hidden">
                <div class="p-6 border-b border-white/10 flex items-center justify-between">
                    <h2 class="text-sm font-black uppercase italic tracking-widest text-white">Гарантийные обращения</h2>
                    <Link href="/admin/store/warranty" class="text-[10px] uppercase font-black tracking-widest text-amber-400">Все</Link>
                </div>
                <div class="divide-y divide-white/5">
                    <Link v-for="row in desk.warranties" :key="row.id" href="/admin/store/warranty"
                          class="px-6 py-5 block hover:bg-white/[0.02]">
                        <div class="text-white text-sm font-black uppercase italic truncate">{{ row.product_name || ('Гарантия #' + row.id) }}</div>
                        <div class="text-white/50 text-xs mt-1">
                            {{ row.client || 'Без клиента' }}
                            <span v-if="row.serial"> · {{ row.serial }}</span>
                        </div>
                    </Link>
                </div>
            </div>
        </div>

        <div v-if="desk.team.length" class="bg-[#0a0a0a] border border-white/5 rounded-[1.125rem] overflow-hidden">
            <div class="p-6 border-b border-white/10">
                <h2 class="text-sm font-black uppercase italic tracking-widest text-white">Команда магазина</h2>
            </div>
            <div class="grid md:grid-cols-3 divide-y md:divide-y-0 md:divide-x divide-white/5">
                <div v-for="person in desk.team" :key="person.id" class="px-6 py-5">
                    <div class="text-white text-sm font-black uppercase italic">{{ person.name }}</div>
                    <div class="text-white/40 text-[10px] uppercase font-black tracking-widest mt-1">{{ person.role_label }}</div>
                </div>
            </div>
        </div>
    </div>
</template>
