<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const props = defineProps<{
    pcs: any[]
    clients: any[]
    components: any[]
    componentTypes: Record<string, string>
    staff: any[]
    taxModes: Record<string, string>
    statuses: Record<string, string>
    filters: { status?: string | null }
    canManage: boolean
    canAssemble: boolean
}>()

const money = (v: any) => v == null ? '—' : Number(v).toLocaleString('ru-RU') + ' ₽'

const showForm = ref(false)
const form = useForm({
    title: '',
    store_client_id: null as number | null,
    assembled_by: null as number | null,
    accepted_by: null as number | null,
    issued_by: null as number | null,
    serial_number: '',
    sale_price: null as number | null,
    sale_tax_mode: 'with_tax' as string | null,
    sold_at: '',
    status: 'assembling',
    notes: '',
    component_ids: [] as number[],
})

const filterStatus = computed({
    get: () => props.filters?.status || '',
    set: (v: string) => router.get('/admin/store/built-pcs', v ? { status: v } : {}, { preserveState: true }),
})

const openCreate = () => {
    form.reset()
    form.status = 'assembling'
    form.sale_tax_mode = 'with_tax'
    form.component_ids = []
    showForm.value = true
}

const save = () => {
    form.post('/admin/store/built-pcs', { onSuccess: () => { showForm.value = false } })
}

const remove = (id: number) => {
    if (!confirm('Удалить сборку?')) return
    router.delete(`/admin/store/built-pcs/${id}`)
}

const printBarcodePos = (id: number) => {
    router.post(`/admin/store/built-pcs/${id}/print-barcode-pos`, {}, { preserveScroll: true })
}

const toggleComponent = (id: number) => {
    const idx = form.component_ids.indexOf(id)
    if (idx >= 0) form.component_ids.splice(idx, 1)
    else form.component_ids.push(id)
}

const availableComponents = computed(() => {
    const selected = new Set(form.component_ids)
    const fromStock = props.components || []
    const linked = (props.pcs || [])
        .flatMap((pc: any) => pc.component_links || [])
        .filter((l: any) => l.store_component_id && selected.has(l.store_component_id))
        .map((l: any) => ({
            id: l.store_component_id,
            name: l.name,
            type: l.type,
            purchase_price: null,
            from_build: true,
        }))

    const byId = new Map<number, any>()
    for (const c of [...fromStock, ...linked]) {
        if (!byId.has(c.id)) byId.set(c.id, c)
    }
    return Array.from(byId.values())
})

const canDeletePc = (pc: any) => {
    if (pc.store_order_id) return false
    return props.canManage
}

const buildLabel = (pc: any) => {
    const links = pc.component_links || []
    if (!links.length) return 'Комплектация не указана'
    return links.map((l: any) => l.name).join(' · ')
}
</script>

<template>
    <Head title="REACTOR | Готовые ПК" />
    <AdminLayout>
        <div class="max-w-7xl mx-auto space-y-8 pb-20 px-4 font-mono">
            <div class="flex flex-wrap justify-between items-end gap-4 border-b border-white/10 pb-6">
                <div>
                    <h1 class="text-3xl font-black uppercase italic tracking-tighter">
                        Готовые <span class="text-amber-400">ПК</span>
                    </h1>
                    <p class="text-white/20 text-[10px] uppercase tracking-[0.4em] font-black mt-2 italic">
                        Сборки, продажа, серийники и налоги
                    </p>
                </div>
                <div class="flex gap-3">
                    <select v-model="filterStatus" class="bg-black border border-white/10 rounded-xl px-4 py-3 text-[10px] uppercase font-black">
                        <option value="">Все статусы</option>
                        <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
                    </select>
                    <button v-if="canAssemble" @click="openCreate"
                            class="px-6 py-4 bg-amber-500 text-black font-black uppercase tracking-widest text-[10px] rounded-2xl">
                        + Сборка
                    </button>
                </div>
            </div>

            <div class="space-y-4">
                <div v-for="pc in pcs" :key="pc.id" class="border border-white/5 bg-[#080808] rounded-2xl p-6 space-y-3">
                    <div class="flex flex-wrap justify-between gap-3">
                        <div>
                            <div class="font-black uppercase text-sm">
                                {{ pc.title || ('Сборка #' + pc.id) }}
                                <span class="text-amber-400/80 font-black text-[10px] ml-2 tracking-widest">{{ statuses[pc.status] || pc.status }}</span>
                            </div>
                            <div class="text-[10px] text-white/30 uppercase tracking-widest mt-1">
                                S/N {{ pc.serial_number || '—' }}
                                <span v-if="pc.client"> · {{ pc.client.name }}</span>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="font-black text-amber-400">{{ money(pc.sale_price) }}</div>
                            <div class="text-[10px] text-white/30 uppercase mt-1">
                                {{ pc.sale_tax_mode ? taxModes[pc.sale_tax_mode] : '—' }}
                                <span v-if="pc.sold_at"> · {{ String(pc.sold_at).slice(0, 10) }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="text-xs text-white/40">{{ buildLabel(pc) }}</div>
                    <div class="text-[10px] uppercase tracking-widest text-white/25 flex flex-wrap gap-x-4 gap-y-1">
                        <span>Собрал: {{ pc.assembler?.name || '—' }}</span>
                        <span>Принял заказ: {{ pc.acceptor?.name || '—' }}</span>
                        <span>Выдал: {{ pc.issuer?.name || '—' }}</span>
                    </div>
                    <div class="flex flex-wrap gap-2 pt-1">
                        <a :href="`/admin/store/built-pcs/${pc.id}/print-barcode`" target="_blank"
                           class="px-3 py-2 rounded-xl border border-white/15 text-[10px] uppercase font-black text-white/60">QR</a>
                        <button type="button" @click="printBarcodePos(pc.id)"
                                class="px-3 py-2 rounded-xl border border-amber-500/40 text-[10px] uppercase font-black text-amber-400">QR POS</button>
                        <a :href="`/admin/store/built-pcs/${pc.id}/print-talon`" target="_blank"
                           class="px-3 py-2 rounded-xl border border-white/15 text-[10px] uppercase font-black text-white/60">Талон</a>
                        <button v-if="canDeletePc(pc)" @click="remove(pc.id)" class="px-3 py-2 rounded-xl border border-red-500/30 text-[10px] uppercase font-black text-red-400">Del</button>
                    </div>
                </div>
                <div v-if="!pcs.length" class="text-white/30 text-sm py-10 text-center">Сборок пока нет</div>
            </div>
        </div>

        <div v-if="showForm" class="fixed inset-0 bg-black/70 flex items-start justify-center z-50 p-4 overflow-y-auto" @click.self="showForm = false">
            <form class="bg-[#0a0a0a] border border-white/10 rounded-3xl p-8 w-full max-w-2xl space-y-3 my-8" @submit.prevent="save">
                <h3 class="font-black uppercase italic text-xl mb-2">Новая сборка</h3>
                <input v-model="form.title" placeholder="Название сборки" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />
                <input v-model="form.serial_number" placeholder="Серийный номер (пусто = авто 10 цифр)" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />

                <div class="grid md:grid-cols-2 gap-3">
                    <select v-model="form.store_client_id" class="bg-black border border-white/10 rounded-xl px-4 py-3 text-sm">
                        <option :value="null">Клиент</option>
                        <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }} · {{ c.phone }}</option>
                    </select>
                    <select v-model="form.status" class="bg-black border border-white/10 rounded-xl px-4 py-3 text-sm">
                        <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
                    </select>
                </div>

                <div class="grid md:grid-cols-3 gap-3">
                    <select v-model="form.assembled_by" class="bg-black border border-white/10 rounded-xl px-4 py-3 text-sm">
                        <option :value="null">Кто собирал</option>
                        <option v-for="s in staff" :key="'a'+s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                    <select v-model="form.accepted_by" class="bg-black border border-white/10 rounded-xl px-4 py-3 text-sm">
                        <option :value="null">Кто принял заказ</option>
                        <option v-for="s in staff" :key="'b'+s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                    <select v-model="form.issued_by" class="bg-black border border-white/10 rounded-xl px-4 py-3 text-sm">
                        <option :value="null">Кто отдал</option>
                        <option v-for="s in staff" :key="'c'+s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                </div>

                <div class="grid md:grid-cols-3 gap-3">
                    <input v-model.number="form.sale_price" type="number" min="0" step="0.01" placeholder="Цена продажи" class="bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />
                    <select v-model="form.sale_tax_mode" class="bg-black border border-white/10 rounded-xl px-4 py-3 text-sm">
                        <option v-for="(label, key) in taxModes" :key="key" :value="key">{{ label }}</option>
                    </select>
                    <input v-model="form.sold_at" type="date" class="bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />
                </div>

                <div>
                    <div class="text-[10px] uppercase tracking-widest text-white/30 font-black mb-2">Комплектация со склада</div>
                    <div class="max-h-48 overflow-y-auto border border-white/10 rounded-xl divide-y divide-white/5">
                        <label v-for="c in availableComponents" :key="c.id" class="flex items-center gap-3 px-4 py-2 text-xs cursor-pointer hover:bg-white/[0.02]">
                            <input type="checkbox" :checked="form.component_ids.includes(c.id)" @change="toggleComponent(c.id)" />
                            <span class="text-amber-400/70 w-28 shrink-0">{{ componentTypes[c.type] || c.type }}</span>
                            <span class="flex-1">{{ c.name }}</span>
                            <span class="text-white/30">{{ c.purchase_price != null ? money(c.purchase_price) : 'в сборке' }}</span>
                        </label>
                        <div v-if="!availableComponents.length" class="px-4 py-3 text-white/30 text-xs">Нет свободных комплектующих на складе</div>
                    </div>
                </div>

                <textarea v-model="form.notes" placeholder="Заметки" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" rows="2" />

                <div class="flex gap-3 justify-end pt-2">
                    <button type="button" class="px-4 py-3 text-[10px] uppercase font-black text-white/40" @click="showForm = false">Отмена</button>
                    <button class="px-6 py-3 bg-amber-500 text-black text-[10px] uppercase font-black rounded-xl" :disabled="form.processing">Сохранить</button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
