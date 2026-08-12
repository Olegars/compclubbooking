<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const props = defineProps<{
    warranties: any[]
    clients: any[]
    orders: any[]
    statuses: string[]
    filters: { status?: string | null }
    canManage: boolean
    canClose: boolean
    posPrintEnabled?: boolean
}>()

const statusLabel: Record<string, string> = {
    active: 'Активна',
    claimed: 'Обращение',
    closed: 'Закрыта',
}

const showCreate = ref(false)
const form = useForm({
    store_client_id: null as number | null,
    store_order_id: null as number | null,
    serial: '',
    product_name: '',
    started_at: '',
    ends_at: '',
    claim_notes: '',
})

const create = () => {
    form.post('/admin/store/warranty', { onSuccess: () => { showCreate.value = false; form.reset() } })
}

const setStatus = (id: number, status: string) => {
    router.post(`/admin/store/warranty/${id}`, { status }, { preserveScroll: true })
}

const printBarcodePos = (id: number) => {
    router.post(`/admin/store/warranty/${id}/print-barcode-pos`, {}, { preserveScroll: true })
}

const filterStatus = computed({
    get: () => props.filters?.status || '',
    set: (v: string) => router.get('/admin/store/warranty', v ? { status: v } : {}, { preserveState: true })
})
</script>

<template>
    <Head title="REACTOR | Гарантия" />
    <AdminLayout>
        <div class="max-w-7xl mx-auto space-y-8 pb-20 px-4 font-mono">
            <div class="flex flex-wrap justify-between items-end gap-4 border-b border-white/10 pb-6">
                <div>
                    <h1 class="text-3xl font-black uppercase italic tracking-tighter">Store <span class="text-amber-400">Warranty</span></h1>
                    <p class="text-white/20 text-[10px] uppercase tracking-[0.4em] font-black mt-2 italic">Гарантийные кейсы магазина</p>
                </div>
                <div class="flex gap-3">
                    <select v-model="filterStatus" class="bg-black border border-white/10 rounded-xl px-4 py-3 text-[10px] uppercase font-black">
                        <option value="">Все</option>
                        <option v-for="s in statuses" :key="s" :value="s">{{ statusLabel[s] || s }}</option>
                    </select>
                    <button v-if="canManage" @click="showCreate = true"
                            class="px-6 py-4 bg-amber-500 text-black font-black uppercase tracking-widest text-[10px] rounded-2xl">
                        + Гарантия
                    </button>
                </div>
            </div>

            <div class="space-y-3">
                <div v-for="w in warranties" :key="w.id" class="border border-white/5 bg-[#080808] rounded-2xl p-5 flex flex-wrap justify-between gap-4">
                    <div>
                        <div class="font-black uppercase text-sm">{{ w.product_name || 'Без названия' }}</div>
                        <div class="text-[10px] text-white/30 uppercase tracking-widest mt-1">
                            {{ statusLabel[w.status] || w.status }}
                            <span v-if="w.serial"> · S/N {{ w.serial }}</span>
                            <span v-if="w.client"> · {{ w.client.name }}</span>
                            <span v-if="w.built_pc"> · сборка #{{ w.built_pc.id }}</span>
                            <span v-else-if="w.order"> · заказ #{{ w.order.id }}</span>
                        </div>
                        <div v-if="w.ends_at" class="text-[10px] text-white/20 mt-2">До {{ w.ends_at }}</div>
                        <div v-if="w.claim_notes" class="text-xs text-white/40 mt-2">{{ w.claim_notes }}</div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a v-if="w.serial" :href="`/admin/store/warranty/${w.id}/print-barcode`" target="_blank"
                           class="px-3 py-2 rounded-xl border border-white/10 text-[10px] uppercase font-black text-white/50">Штрихкод</a>
                        <button v-if="w.serial" type="button" @click="printBarcodePos(w.id)"
                                class="px-3 py-2 rounded-xl border border-amber-500/30 text-[10px] uppercase font-black text-amber-400">Штрихкод POS</button>
                        <a :href="`/admin/store/warranty/${w.id}/print-talon`" target="_blank"
                           class="px-3 py-2 rounded-xl border border-white/10 text-[10px] uppercase font-black text-white/50">Талон</a>
                        <button v-if="canManage" @click="setStatus(w.id, 'claimed')"
                                class="px-3 py-2 rounded-xl border border-white/10 text-[10px] uppercase font-black text-white/50">Обращение</button>
                        <button v-if="canClose" @click="setStatus(w.id, 'closed')"
                                class="px-3 py-2 rounded-xl border border-amber-500/30 text-[10px] uppercase font-black text-amber-400">Закрыть</button>
                        <button v-if="canManage" @click="setStatus(w.id, 'active')"
                                class="px-3 py-2 rounded-xl border border-white/10 text-[10px] uppercase font-black text-white/50">Active</button>
                    </div>
                </div>
                <div v-if="!warranties.length" class="text-white/30 text-sm py-10 text-center">Гарантий нет</div>
            </div>
        </div>

        <div v-if="showCreate" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 p-4" @click.self="showCreate = false">
            <form class="bg-[#0a0a0a] border border-white/10 rounded-3xl p-8 w-full max-w-lg space-y-4" @submit.prevent="create">
                <h3 class="font-black uppercase italic text-xl">Новая гарантия</h3>
                <p class="text-[10px] text-white/30 uppercase tracking-widest">Для сборок ПК гарантия создаётся автоматически. Здесь — ручной кейс.</p>
                <input v-model="form.product_name" placeholder="Товар" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />
                <input v-model="form.serial" placeholder="Серийный номер (пусто = авто 10 цифр)" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />
                <select v-model="form.store_client_id" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm">
                    <option :value="null">Клиент</option>
                    <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <select v-model="form.store_order_id" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm">
                    <option :value="null">Заказ (каталог)</option>
                    <option v-for="o in orders" :key="o.id" :value="o.id">#{{ o.id }} · {{ o.status }}</option>
                </select>
                <div class="grid grid-cols-2 gap-3">
                    <input v-model="form.started_at" type="date" class="bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />
                    <input v-model="form.ends_at" type="date" class="bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />
                </div>
                <textarea v-model="form.claim_notes" placeholder="Заметки" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" rows="2" />
                <div class="flex gap-3 justify-end">
                    <button type="button" class="px-4 py-3 text-[10px] uppercase font-black text-white/40" @click="showCreate = false">Отмена</button>
                    <button class="px-6 py-3 bg-amber-500 text-black text-[10px] uppercase font-black rounded-xl" :disabled="form.processing">Создать</button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
