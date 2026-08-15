<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useClubName } from '@/Composables/useClubName'

const clubName = useClubName()

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
const detail = ref<any | null>(null)
const replaceFor = ref<any | null>(null)
const form = useForm({
    store_client_id: null as number | null,
    store_order_id: null as number | null,
    serial: '',
    product_name: '',
    started_at: '',
    ends_at: '',
    claim_notes: '',
})
const replaceForm = useForm({
    store_component_id: null as number | null,
    name: '',
    serials: [''] as string[],
    purchase_price: 0 as number | null,
    warranty_months: null as number | null,
    notes: '',
})

const create = () => {
    form.post('/admin/store/warranty', { onSuccess: () => { showCreate.value = false; form.reset() } })
}

const setStatus = (id: number, status: string) => {
    router.post(`/admin/store/warranty/${id}`, { status }, { preserveScroll: true })
}

const refreshDetail = (warrantyId: number) => {
    const fresh = props.warranties.find((w) => w.id === warrantyId)
    detail.value = fresh || null
}

const sendToRepair = (warrantyId: number, componentId: number | null | undefined) => {
    if (!componentId) return
    if (!confirm('Передать комплектующую в ремонт? Связь со сборкой сохранится.')) return
    router.post(`/admin/store/warranty/${warrantyId}/send-to-repair`, {
        store_component_id: componentId,
    }, {
        preserveScroll: true,
        onSuccess: () => refreshDetail(warrantyId),
    })
}

const returnFromRepair = (warrantyId: number, componentId: number | null | undefined) => {
    if (!componentId) return
    if (!confirm('Вернуть комплектующую в сборку после ремонта?')) return
    router.post(`/admin/store/warranty/${warrantyId}/return-from-repair`, {
        store_component_id: componentId,
    }, {
        preserveScroll: true,
        onSuccess: () => refreshDetail(warrantyId),
    })
}

const openReplace = (item: any) => {
    replaceFor.value = item
    replaceForm.store_component_id = item.store_component_id
    replaceForm.name = item.name || ''
    replaceForm.serials = ['']
    replaceForm.purchase_price = null
    replaceForm.warranty_months = item.warranty_months ?? null
    replaceForm.notes = ''
}

const submitReplace = () => {
    if (!detail.value || !replaceForm.store_component_id) return
    const warrantyId = detail.value.id
    replaceForm.serials = replaceForm.serials.map((s) => String(s || '').trim()).filter(Boolean)
    replaceForm.post(`/admin/store/warranty/${warrantyId}/replace-component`, {
        preserveScroll: true,
        onSuccess: () => {
            replaceFor.value = null
            refreshDetail(warrantyId)
        },
    })
}

const printBarcodePos = (id: number) => {
    router.post(`/admin/store/warranty/${id}/print-barcode-pos`, {}, { preserveScroll: true })
}

const openDetail = (w: any) => {
    detail.value = w
}

const fmtDate = (v: any): string => {
    if (!v) return '—'
    const d = new Date(v)
    if (Number.isNaN(d.getTime())) return String(v).slice(0, 10)
    return d.toLocaleDateString('ru-RU')
}

const filterStatus = computed({
    get: () => props.filters?.status || '',
    set: (v: string) => router.get('/admin/store/warranty', v ? { status: v } : {}, { preserveState: true }),
})
</script>

<template>
    <Head :title="`${clubName} | Гарантия`" />
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
                <div v-for="w in warranties" :key="w.id"
                     class="border border-white/5 bg-[#080808] rounded-2xl p-5 flex flex-wrap justify-between gap-4 cursor-pointer hover:bg-white/[0.02] transition-colors"
                     @click="openDetail(w)">
                    <div>
                        <div class="font-black uppercase text-sm">{{ w.product_name || 'Без названия' }}</div>
                        <div class="text-[10px] text-white/30 uppercase tracking-widest mt-1">
                            {{ statusLabel[w.status] || w.status }}
                            <span v-if="w.serial"> · S/N {{ w.serial }}</span>
                            <span v-if="w.client"> · {{ w.client.name }}</span>
                            <span v-if="w.built_pc"> · сборка #{{ w.built_pc.id }}</span>
                            <span v-else-if="w.order"> · заказ #{{ w.order.id }}</span>
                        </div>
                        <div v-if="w.warranty_label"
                             class="text-[10px] font-black uppercase tracking-wider mt-2"
                             :class="{
                                 'text-red-300': w.warranty_state === 'expired',
                                 'text-amber-300': w.warranty_state === 'expiring',
                                 'text-emerald-300/80': w.warranty_state === 'active',
                             }">
                            {{ w.warranty_label }}
                            <span v-if="w.ends_at" class="text-white/25 font-normal normal-case tracking-normal"> · до {{ fmtDate(w.ends_at) }}</span>
                        </div>
                        <div v-else-if="w.ends_at" class="text-[10px] text-white/20 mt-2">До {{ fmtDate(w.ends_at) }}</div>
                        <div v-if="w.claim_notes" class="text-xs text-white/40 mt-2">{{ w.claim_notes }}</div>
                    </div>
                    <div class="flex flex-wrap gap-2" @click.stop>
                        <a v-if="w.serial" :href="`/admin/store/warranty/${w.id}/print-barcode`" target="_blank"
                           class="px-3 py-2 rounded-xl border border-white/10 text-[10px] uppercase font-black text-white/50">QR</a>
                        <button v-if="w.serial" type="button" @click="printBarcodePos(w.id)"
                                class="px-3 py-2 rounded-xl border border-amber-500/30 text-[10px] uppercase font-black text-amber-400">QR POS</button>
                        <a :href="`/admin/store/warranty/${w.id}/print-talon`" target="_blank"
                           class="px-3 py-2 rounded-xl border border-white/10 text-[10px] uppercase font-black text-white/50">Талон</a>
                        <button v-if="canManage" @click="setStatus(w.id, 'claimed')"
                                class="px-3 py-2 rounded-xl border border-white/10 text-[10px] uppercase font-black text-white/50">Обращение</button>
                        <button v-if="canClose" @click="setStatus(w.id, 'closed')"
                                class="px-3 py-2 rounded-xl border border-amber-500/30 text-[10px] uppercase font-black text-amber-400">Закрыть</button>
                        <button v-if="canManage" @click="setStatus(w.id, 'active')"
                                class="px-3 py-2 rounded-xl border text-[10px] uppercase font-black"
                                :class="w.has_repair
                                    ? 'border-red-500/50 text-red-400 bg-red-500/10'
                                    : 'border-white/10 text-white/50'"
                                :title="w.has_repair ? 'В сборке есть деталь в ремонте' : ''">
                            Active
                        </button>
                    </div>
                </div>
                <div v-if="!warranties.length" class="text-white/30 text-sm py-10 text-center">Гарантий нет</div>
            </div>
        </div>

        <div v-if="detail" class="fixed inset-0 bg-black/70 flex items-start justify-center z-50 p-4 overflow-y-auto" @click.self="detail = null">
            <div class="bg-[#0a0a0a] border border-white/10 rounded-3xl p-7 w-full max-w-xl space-y-5 my-8" @click.stop>
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="text-[10px] uppercase tracking-widest text-amber-400/80 font-black">{{ statusLabel[detail.status] || detail.status }}</div>
                        <h3 class="font-black uppercase italic text-xl mt-1 leading-tight">{{ detail.product_name || 'Гарантия' }}</h3>
                        <div class="text-[10px] text-white/35 uppercase tracking-widest mt-2">
                            <span v-if="detail.serial">S/N {{ detail.serial }}</span>
                            <span v-if="detail.client"> · {{ detail.client.name }}</span>
                            <span v-if="detail.built_pc"> · сборка #{{ detail.built_pc.id }}</span>
                        </div>
                    </div>
                    <button type="button" class="text-white/40 hover:text-white text-2xl leading-none px-2" @click="detail = null">×</button>
                </div>

                <div v-if="detail.warranty_label"
                     class="rounded-2xl border px-4 py-3 text-xs font-black uppercase tracking-wider"
                     :class="{
                         'border-red-500/40 bg-red-500/10 text-red-300': detail.warranty_state === 'expired',
                         'border-amber-500/40 bg-amber-500/10 text-amber-300': detail.warranty_state === 'expiring',
                         'border-emerald-500/30 bg-emerald-500/10 text-emerald-300': detail.warranty_state === 'active',
                     }">
                    {{ detail.warranty_label }}
                    <span v-if="detail.ends_at" class="block mt-1 text-[10px] font-normal normal-case tracking-normal text-white/40">
                        {{ fmtDate(detail.started_at) }} — {{ fmtDate(detail.ends_at) }}
                        <span v-if="detail.warranty_months"> · {{ detail.warranty_months }} мес.</span>
                    </span>
                </div>

                <div class="grid grid-cols-2 gap-3 text-xs">
                    <div class="rounded-2xl border border-white/10 p-3">
                        <div class="text-[10px] uppercase tracking-widest text-white/30 font-black mb-1">Клиент</div>
                        <div>{{ detail.client?.name || '—' }}</div>
                        <div v-if="detail.client?.phone" class="text-white/40 mt-1">{{ detail.client.phone }}</div>
                    </div>
                    <div class="rounded-2xl border border-white/10 p-3">
                        <div class="text-[10px] uppercase tracking-widest text-white/30 font-black mb-1">Ремонт</div>
                        <div>{{ detail.repair_days ? detail.repair_days + ' дн.' : '—' }}</div>
                    </div>
                </div>

                <div>
                    <div class="text-[10px] uppercase tracking-widest text-amber-400/80 font-black mb-3">Комплектующие сборки</div>
                    <div v-if="detail.build_items?.length" class="space-y-2">
                        <div v-for="(item, idx) in detail.build_items" :key="idx"
                             class="rounded-2xl border border-white/10 px-4 py-3 text-xs flex flex-wrap items-center justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="text-[10px] uppercase tracking-widest text-white/30 font-black">{{ item.type_label || item.type || '—' }}</div>
                                <div class="font-black uppercase mt-1">{{ item.name || '—' }}</div>
                                <div v-if="item.warranty_number || (item.serials && item.serials.length)"
                                     class="font-mono text-cyan-400/80 text-[11px] mt-1">
                                    {{ item.warranty_number || (item.serials || []).join(' · ') }}
                                </div>
                                <div v-if="item.warranty_months" class="text-[10px] text-white/25 mt-1 uppercase tracking-widest">
                                    {{ item.warranty_months }} мес. от поступления
                                </div>
                                <div v-if="item.sent_to_repair_label" class="text-[10px] text-amber-400 font-black uppercase tracking-wider mt-2">
                                    {{ item.sent_to_repair_label }}
                                </div>
                                <div v-if="item.replaced_by_component_id" class="text-[10px] text-white/35 mt-1 uppercase tracking-widest">
                                    Заменена на ID {{ item.replaced_by_component_id }}
                                </div>
                                <div v-if="item.replaces_component_id" class="text-[10px] text-white/35 mt-1 uppercase tracking-widest">
                                    Замена ID {{ item.replaces_component_id }}
                                </div>
                            </div>
                            <div class="flex flex-col items-end gap-2 shrink-0">
                                <div class="flex flex-wrap justify-end gap-2">
                                    <button v-if="canManage && item.can_send_to_repair && item.store_component_id"
                                            type="button"
                                            class="px-3 py-2 rounded-xl border border-amber-500/40 text-[10px] uppercase font-black text-amber-400 hover:bg-amber-500/10"
                                            @click="sendToRepair(detail.id, item.store_component_id)">
                                        В ремонт
                                    </button>
                                    <button v-if="canManage && item.can_return_from_repair"
                                            type="button"
                                            class="px-3 py-2 rounded-xl border border-emerald-500/40 text-[10px] uppercase font-black text-emerald-300 hover:bg-emerald-500/10"
                                            @click="returnFromRepair(detail.id, item.store_component_id)">
                                        Вернуть в сборку
                                    </button>
                                    <button v-if="canManage && item.can_replace"
                                            type="button"
                                            class="px-3 py-2 rounded-xl border border-red-500/40 text-[10px] uppercase font-black text-red-300 hover:bg-red-500/10"
                                            @click="openReplace(item)">
                                        Списать со склада
                                    </button>
                                </div>
                                <div v-if="item.warranty_badge != null"
                                     class="w-12 h-12 rounded-xl border flex flex-col items-center justify-center font-black leading-none"
                                     :class="{
                                         'border-red-500/50 bg-red-500/15 text-red-300': item.warranty_state === 'expired',
                                         'border-amber-500/50 bg-amber-500/15 text-amber-300': item.warranty_state === 'expiring',
                                         'border-emerald-500/40 bg-emerald-500/10 text-emerald-300': item.warranty_state === 'active',
                                         'border-white/15 bg-white/5 text-white/40': item.warranty_state === 'none',
                                     }"
                                     :title="item.warranty_label || (item.warranty_months ? (item.warranty_months + ' мес.') : '')">
                                    <span class="text-sm">{{ item.warranty_badge }}</span>
                                    <span class="text-[8px] uppercase tracking-wider mt-0.5 opacity-70">дн</span>
                                </div>
                                <div v-else
                                     class="w-12 h-12 rounded-xl border border-white/10 bg-white/[0.03] text-white/20 flex items-center justify-center text-[10px] font-black"
                                     title="Срок гарантии не указан">—</div>
                            </div>
                        </div>
                    </div>
                    <div v-else class="text-white/30 text-xs py-4 text-center border border-dashed border-white/10 rounded-2xl">
                        Комплектация не сохранена
                    </div>
                </div>

                <div v-if="detail.claim_notes" class="text-xs text-white/40">
                    <div class="text-[10px] uppercase tracking-widest text-white/30 font-black mb-1">Заметки</div>
                    {{ detail.claim_notes }}
                </div>

                <div class="flex flex-wrap gap-2 justify-end pt-1">
                    <a v-if="detail.serial" :href="`/admin/store/warranty/${detail.id}/print-talon`" target="_blank"
                       class="px-4 py-3 rounded-xl border border-white/10 text-[10px] uppercase font-black text-white/50">Талон</a>
                    <button type="button" class="px-5 py-3 bg-white/5 border border-white/10 rounded-xl text-[10px] uppercase font-black"
                            @click="detail = null">Закрыть</button>
                </div>
            </div>
        </div>

        <div v-if="replaceFor" class="fixed inset-0 bg-black/80 flex items-start justify-center z-[60] p-4 overflow-y-auto" @click.self="replaceFor = null">
            <form class="bg-[#0a0a0a] border border-white/10 rounded-3xl p-7 w-full max-w-lg space-y-4 my-8" @submit.prevent="submitReplace">
                <h3 class="font-black uppercase italic text-xl">Списать и заменить</h3>
                <p class="text-[10px] text-white/35 uppercase tracking-widest leading-relaxed">
                    Старая деталь #{{ replaceFor.store_component_id }} будет списана. Новая попадёт на склад и в сборку с пометкой «замена ID {{ replaceFor.store_component_id }}».
                </p>
                <div>
                    <label class="block text-[10px] uppercase tracking-widest text-white/40 font-black mb-1.5">Название новой детали</label>
                    <input v-model="replaceForm.name" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" required />
                </div>
                <div>
                    <label class="block text-[10px] uppercase tracking-widest text-white/40 font-black mb-1.5">Серийный номер</label>
                    <input v-model="replaceForm.serials[0]" class="w-full bg-black border border-cyan-500/30 rounded-xl px-4 py-3 text-sm text-cyan-300" placeholder="S/N новой детали" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[10px] uppercase tracking-widest text-white/40 font-black mb-1.5">Закупка, ₽</label>
                        <input v-model.number="replaceForm.purchase_price" type="number" min="0" step="0.01" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />
                    </div>
                    <div>
                        <label class="block text-[10px] uppercase tracking-widest text-white/40 font-black mb-1.5">Гарантия, мес.</label>
                        <input v-model.number="replaceForm.warranty_months" type="number" min="0" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />
                    </div>
                </div>
                <div>
                    <label class="block text-[10px] uppercase tracking-widest text-white/40 font-black mb-1.5">Заметка</label>
                    <textarea v-model="replaceForm.notes" rows="2" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />
                </div>
                <div class="flex gap-3 justify-end pt-1">
                    <button type="button" class="px-4 py-3 text-[10px] uppercase font-black text-white/40" @click="replaceFor = null">Отмена</button>
                    <button class="px-6 py-3 bg-red-500/90 text-black text-[10px] uppercase font-black rounded-xl" :disabled="replaceForm.processing">
                        Списать и поставить замену
                    </button>
                </div>
            </form>
        </div>

        <div v-if="showCreate" class="fixed inset-0 bg-black/70 flex items-start justify-center z-50 p-4 overflow-y-auto" @click.self="showCreate = false">
            <form class="bg-[#0a0a0a] border border-white/10 rounded-3xl p-8 w-full max-w-lg space-y-4 my-8" @submit.prevent="create">
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
