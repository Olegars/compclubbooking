<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import axios from 'axios'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import SuggestInput from '@/Components/SuggestInput.vue'
import { useAdminBarcodeScanner } from '@/Composables/useAdminBarcodeScanner'
import { useToast } from '@/Composables/useToast'
import { normalizeScanLayout } from '@/utils/scanKeyboard'
import { inferSpecFills } from '@/utils/storeSpecInfer'

type SpecField = { key: string, label: string, suggest: string }

const props = defineProps<{
    components: any[]
    suppliers: { id: number, name: string }[]
    types: Record<string, string>
    statuses: Record<string, string>
    specSchemas: Record<string, SpecField[]>
    specDictionaries: Record<string, string[]>
    filters: { type?: string | null }
    canManage: boolean
    canReceive: boolean
}>()

const money = (v: any) => Number(v || 0).toLocaleString('ru-RU') + ' ₽'
const fmtDt = (v: any): string => {
    if (!v) return '—'
    const d = new Date(v)
    if (Number.isNaN(d.getTime())) return String(v).slice(0, 16).replace('T', ' ')
    return d.toLocaleString('ru-RU', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    })
}
const fmtDate = (v: any): string => {
    if (!v) return '—'
    const d = new Date(v)
    if (Number.isNaN(d.getTime())) return String(v).slice(0, 10)
    return d.toLocaleDateString('ru-RU')
}
const { info } = useToast()
const { receiveMode, enableReceiveMode, disableReceiveMode } = useAdminBarcodeScanner()

const showForm = ref(false)
const showSupplier = ref(false)
const detail = ref<any | null>(null)
const serialInput = ref<HTMLInputElement | null>(null)

const specs = reactive<Record<string, string>>({})
const remoteSuggest = reactive<Record<string, string[]>>({})

const form = useForm({
    id: null as number | null,
    name: '',
    original_name: '',
    type: 'cpu',
    store_supplier_id: null as number | null,
    purchase_price: 0,
    warranty_number: '',
    serials: [''] as string[],
    warranty_months: 36 as number | null,
    qty: 1,
    status: 'in_stock',
    notes: '',
    specs: {} as Record<string, string>,
})

const supplierForm = useForm({
    name: '',
    phone: '',
    notes: '',
})

const filterType = computed({
    get: () => props.filters?.type || '',
    set: (v: string) => router.get('/admin/store/warehouse', v ? { type: v } : {}, { preserveState: true }),
})

const activeFields = computed(() => props.specSchemas[form.type] || [])

const ramModuleCount = computed(() => {
    if (form.type !== 'ram') return 1
    const raw = String(specs.modules || '1').replace(/\D+/g, '')
    const n = parseInt(raw || '1', 10)
    return Math.min(8, Math.max(1, Number.isFinite(n) ? n : 1))
})

const serialSlotCount = computed(() => (form.type === 'ram' ? ramModuleCount.value : 1))

const syncSerialSlots = (count: number, preset: string[] = []) => {
    const next = Array.from({ length: count }, (_, i) => preset[i] || form.serials[i] || '')
    form.serials = next
    form.warranty_number = next.find((s) => s.trim()) || ''
}

watch(serialSlotCount, (n) => syncSerialSlots(n), { immediate: false })

const composedName = computed(() => {
    const s = (k: string) => (specs[k] || '').trim()
    if (form.type === 'cpu') {
        let seriesModel = ''
        if (s('series') && s('model')) {
            seriesModel = s('series').toLowerCase().includes('ultra')
                ? `${s('series')} ${s('model')}`
                : `${s('series')}-${s('model')}`
        } else {
            seriesModel = `${s('series')} ${s('model')}`.trim()
        }
        return [s('brand'), seriesModel, s('socket') ? `(${s('socket')})` : ''].filter(Boolean).join(' ')
    }
    if (form.type === 'ram') {
        const cap = s('capacity')
        const modules = ramModuleCount.value
        const capLabel = cap ? (modules > 1 ? `${modules}x${cap}` : cap) : ''
        return [s('brand'), s('ddr'), capLabel, s('speed'), s('form')].filter(Boolean).join(' ')
    }
    if (form.type === 'motherboard') {
        return [s('brand'), s('chipset'), s('model'), s('socket') ? `(${s('socket')})` : ''].filter(Boolean).join(' ')
    }
    if (form.type === 'gpu') {
        return [s('brand'), s('chip'), s('vram')].filter(Boolean).join(' ')
    }
    if (form.type === 'storage_ssd') {
        return [s('brand'), s('model'), s('capacity'), s('interface')].filter(Boolean).join(' ')
    }
    if (form.type === 'storage_hdd') {
        return [s('brand'), s('capacity'), s('rpm') ? `${s('rpm')}rpm` : ''].filter(Boolean).join(' ')
    }
    if (form.type === 'psu') {
        return [s('brand'), s('wattage') ? `${s('wattage')}W` : '', s('cert')].filter(Boolean).join(' ')
    }
    if (form.type === 'case') {
        return [s('brand'), s('model'), s('form')].filter(Boolean).join(' ')
    }
    if (form.type === 'cooler') {
        return [s('brand'), s('kind'), s('model')].filter(Boolean).join(' ')
    }
    if (form.type === 'fan') {
        return [s('brand'), s('size')].filter(Boolean).join(' ')
    }
    if (form.type === 'network') {
        return [s('brand'), s('kind'), s('model')].filter(Boolean).join(' ')
    }
    if (form.type === 'os') return s('name')
    if (form.type === 'other') return s('title')
    return ''
})

const nameTouched = ref(false)

watch(composedName, (v) => {
    if (!nameTouched.value) form.name = v
})

const resetSpecs = (preset: Record<string, string> = {}) => {
    Object.keys(specs).forEach(k => delete specs[k])
    for (const f of activeFields.value) {
        specs[f.key] = preset[f.key] || ''
    }
    if (form.type === 'ram' && !specs.modules) {
        specs.modules = '1'
    }
}

watch(() => form.type, () => {
    nameTouched.value = false
    resetSpecs()
    form.name = ''
    syncSerialSlots(serialSlotCount.value, form.serials)
})

const applySpecInfer = (changedKey: string) => {
    if (!['cpu', 'motherboard'].includes(form.type)) return
    const fills = inferSpecFills(form.type, changedKey, { ...specs })
    for (const [k, v] of Object.entries(fills)) {
        if (v && specs[k] !== v) specs[k] = v
    }
}

const onSpecUpdate = (key: string, value: string) => {
    specs[key] = value
    applySpecInfer(key)
    // Любое изменение конструктора → пересобрать название
    nameTouched.value = false
    form.name = composedName.value
}

const onNameInput = () => {
    nameTouched.value = true
}

const suggestionsFor = (field: SpecField) => {
    const dict = props.specDictionaries[field.suggest] || []
    const remote = remoteSuggest[field.key] || []
    return Array.from(new Set([...remote, ...dict]))
}

let suggestTimer: ReturnType<typeof setTimeout> | null = null
const onSuggestSearch = (field: SpecField, q: string) => {
    if (suggestTimer) clearTimeout(suggestTimer)
    if ((q || '').trim().length < 3) {
        remoteSuggest[field.key] = []
        return
    }
    suggestTimer = setTimeout(async () => {
        try {
            const { data } = await axios.get('/admin/store/warehouse/suggest', {
                params: { type: form.type, field: field.key, q },
            })
            remoteSuggest[field.key] = data.items || []
        } catch {
            remoteSuggest[field.key] = []
        }
    }, 120)
}

const processReceiveScan = async (code: string) => {
    const serial = normalizeScanLayout(code)
    if (!serial) return

    if (!showForm.value) {
        openCreate(serial)
    } else {
        const idx = form.serials.findIndex((s) => !String(s || '').trim())
        if (idx >= 0) form.serials[idx] = serial
        else if (form.serials.length === 1) form.serials[0] = serial
        else form.serials[form.serials.length - 1] = serial
        form.warranty_number = form.serials.find((s) => String(s || '').trim()) || serial
    }

    info(serialSlotCount.value > 1 ? 'Серийник планки подставлен' : 'Серийный номер подставлен')
    requestAnimationFrame(() => serialInput.value?.focus())
}

const setReceiveMode = (on: boolean) => {
    if (on) enableReceiveMode(processReceiveScan)
    else disableReceiveMode()
}

onMounted(() => {
    if (props.canReceive) setReceiveMode(true)
})

onUnmounted(() => disableReceiveMode())

const openCreate = (serial = '') => {
    form.reset()
    form.id = null
    form.type = 'cpu'
    form.warranty_months = 36
    form.qty = 1
    form.status = 'in_stock'
    form.purchase_price = 0
    form.serials = [serial]
    form.warranty_number = serial
    form.name = ''
    form.original_name = ''
    nameTouched.value = false
    resetSpecs()
    showForm.value = true
    requestAnimationFrame(() => serialInput.value?.focus())
}

const openEdit = (c: any) => {
    if (c.status === 'sold') return
    detail.value = null
    form.id = c.id
    form.name = c.name
    form.original_name = c.original_name || ''
    nameTouched.value = true
    form.type = c.type
    form.store_supplier_id = c.store_supplier_id
    form.purchase_price = Number(c.purchase_price)
    const list = Array.isArray(c.serials) && c.serials.length
        ? c.serials.map((s: any) => String(s || ''))
        : [c.warranty_number || c.barcode || '']
    form.serials = list.length ? list : ['']
    form.warranty_number = form.serials.find((s: string) => s.trim()) || ''
    form.warranty_months = c.warranty_months
    form.qty = 1
    form.status = c.status
    form.notes = c.notes || ''
    resetSpecs(c.specs || {})
    if (form.type === 'ram' && !specs.modules) specs.modules = String(Math.max(1, form.serials.length))
    syncSerialSlots(serialSlotCount.value, form.serials)
    showForm.value = true
}

const openDetail = (c: any) => {
    detail.value = c
}

const canEditRow = (c: any) => props.canManage && c.status !== 'sold'

const save = () => {
    form.specs = { ...specs }
    if (!form.name) form.name = composedName.value
    form.serials = form.serials.map((s) => String(s || '').trim()).filter(Boolean)
    if (form.serials.length === 0 && form.warranty_number) {
        form.serials = [String(form.warranty_number).trim()]
    }
    form.warranty_number = form.serials[0] || ''
    form.qty = 1
    if (form.id) {
        form.put(`/admin/store/warehouse/${form.id}`, { onSuccess: () => { showForm.value = false } })
    } else {
        form.post('/admin/store/warehouse', { onSuccess: () => { showForm.value = false } })
    }
}

const remove = (id: number) => {
    if (!confirm('Удалить комплектующее?')) return
    detail.value = null
    router.delete(`/admin/store/warehouse/${id}`)
}

const saveSupplier = () => {
    supplierForm.post('/admin/store/warehouse/suppliers', {
        onSuccess: () => { showSupplier.value = false; supplierForm.reset() },
    })
}

const setSerialRef = (el: unknown, idx: number) => {
    if (idx === 0) serialInput.value = (el as HTMLInputElement | null)
}

const onSerialInput = (index: number, event: Event) => {
    const value = normalizeScanLayout((event.target as HTMLInputElement).value)
    form.serials[index] = value
    form.warranty_number = form.serials.find((s) => String(s || '').trim()) || ''
}

const serialsLabel = (c: any) => {
    if (Array.isArray(c.serials) && c.serials.length) {
        return c.serials.filter(Boolean).join(' · ')
    }
    return c.warranty_number || '—'
}

const fieldClass = 'w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm outline-none focus:border-amber-500/50'
const labelClass = 'block text-[10px] uppercase tracking-widest text-white/40 font-black mb-1.5'
</script>

<template>
    <Head title="REACTOR | Склад комплектующих" />
    <AdminLayout>
        <div class="max-w-7xl mx-auto space-y-8 pb-20 px-4 font-mono">
            <div class="flex flex-wrap justify-between items-end gap-4 border-b border-white/10 pb-6">
                <div>
                    <h1 class="text-3xl font-black uppercase italic tracking-tighter">
                        Склад <span class="text-amber-400">комплектующих</span>
                    </h1>
                    <p class="text-white/20 text-[10px] uppercase tracking-[0.4em] font-black mt-2 italic">
                        Конструктор названия · скан серийника
                    </p>
                </div>
                <div class="flex flex-wrap gap-3 items-center">
                    <select v-model="filterType" class="bg-black border border-white/10 rounded-xl px-4 py-3 text-[10px] uppercase font-black">
                        <option value="">Все типы</option>
                        <option v-for="(label, key) in types" :key="key" :value="key">{{ label }}</option>
                    </select>
                    <button v-if="canReceive"
                            type="button"
                            @click="setReceiveMode(!receiveMode)"
                            class="px-5 py-3 rounded-2xl border text-[10px] font-black uppercase tracking-widest transition-all"
                            :class="receiveMode
                                ? 'bg-amber-500/15 border-amber-500/40 text-amber-400'
                                : 'border-white/10 text-white/50'">
                        {{ receiveMode ? 'Сканер ON' : 'Сканер OFF' }}
                    </button>
                    <button v-if="canManage" @click="showSupplier = true"
                            class="px-5 py-3 border border-white/10 text-white/60 font-black uppercase tracking-widest text-[10px] rounded-2xl">
                        + Поставщик
                    </button>
                    <button v-if="canReceive" @click="openCreate()"
                            class="px-6 py-4 bg-amber-500 text-black font-black uppercase tracking-widest text-[10px] rounded-2xl">
                        + Приход
                    </button>
                </div>
            </div>

            <div v-if="receiveMode && canReceive"
                 class="rounded-2xl border border-amber-500/30 bg-amber-500/5 px-5 py-4 text-[11px] text-amber-200/80 uppercase tracking-wider font-black">
                Сканер → серийный номер. Название соберите полями (после 3 символов — подсказки).
            </div>

            <div class="overflow-x-auto border border-white/5 rounded-2xl">
                <table class="w-full text-left text-xs">
                    <thead class="text-[10px] uppercase tracking-widest text-white/30 border-b border-white/5">
                        <tr>
                            <th class="px-4 py-3">Тип</th>
                            <th class="px-4 py-3">Наименование</th>
                            <th class="px-4 py-3">Серийный номер</th>
                            <th class="px-4 py-3">Поставщик</th>
                            <th class="px-4 py-3">Закупка</th>
                            <th class="px-4 py-3">Гарантия</th>
                            <th class="px-4 py-3">Поступление</th>
                            <th class="px-4 py-3">Кто принял</th>
                            <th class="px-4 py-3">Статус</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in components" :key="c.id"
                            class="border-b border-white/5 hover:bg-white/[0.02] cursor-pointer"
                            @click="openDetail(c)">
                            <td class="px-4 py-3 text-amber-400/80">{{ types[c.type] || c.type }}</td>
                            <td class="px-4 py-3 font-black uppercase">{{ c.name }}</td>
                            <td class="px-4 py-3 text-cyan-400/80 font-mono">{{ serialsLabel(c) }}</td>
                            <td class="px-4 py-3 text-white/40">{{ c.supplier?.name || '—' }}</td>
                            <td class="px-4 py-3">{{ money(c.purchase_price) }}</td>
                            <td class="px-4 py-3 text-white/40">{{ c.warranty_months ? c.warranty_months + ' мес.' : '—' }}</td>
                            <td class="px-4 py-3 text-white/40 whitespace-nowrap">{{ fmtDate(c.received_at) }}</td>
                            <td class="px-4 py-3 text-white/40">{{ c.receiver?.name || '—' }}</td>
                            <td class="px-4 py-3 text-white/50">{{ statuses[c.status] || c.status }}</td>
                            <td class="px-4 py-3 text-right space-x-2 whitespace-nowrap" @click.stop>
                                <template v-if="canEditRow(c)">
                                    <button @click="openEdit(c)" class="text-amber-400 uppercase font-black text-[10px]">Edit</button>
                                    <button @click="remove(c.id)" class="text-red-400 uppercase font-black text-[10px]">Del</button>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="!components.length" class="text-white/30 text-sm py-10 text-center">Склад пуст</div>
            </div>
        </div>

        <div v-if="detail" class="fixed inset-0 bg-black/70 flex items-start justify-center z-50 p-4 overflow-y-auto" @click.self="detail = null">
            <div class="bg-[#0a0a0a] border border-white/10 rounded-3xl p-7 w-full max-w-lg space-y-5 my-8" @click.stop>
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="text-[10px] uppercase tracking-widest text-amber-400/80 font-black">{{ types[detail.type] || detail.type }}</div>
                        <h3 class="font-black uppercase italic text-xl mt-1 leading-tight">{{ detail.name }}</h3>
                        <div class="text-white/40 text-[10px] uppercase tracking-widest mt-2">{{ statuses[detail.status] || detail.status }}</div>
                        <div v-if="detail.external_order_id || detail.supplier_sku" class="text-[10px] text-white/30 uppercase tracking-widest mt-1">
                            <span v-if="detail.external_order_id">ext {{ detail.external_order_id }}</span>
                            <span v-if="detail.external_order_id && detail.supplier_sku"> · </span>
                            <span v-if="detail.supplier_sku">sku {{ detail.supplier_sku }}</span>
                        </div>
                    </div>
                    <button type="button" class="text-white/40 hover:text-white text-2xl leading-none px-2" @click="detail = null">×</button>
                </div>

                <div class="grid grid-cols-2 gap-3 text-xs">
                    <div class="rounded-2xl border border-white/10 p-3">
                        <div class="text-[10px] uppercase tracking-widest text-white/30 font-black mb-1">Серийник</div>
                        <div class="font-mono text-cyan-400/90 break-all">{{ serialsLabel(detail) }}</div>
                    </div>
                    <div class="rounded-2xl border border-white/10 p-3">
                        <div class="text-[10px] uppercase tracking-widest text-white/30 font-black mb-1">Закупка</div>
                        <div class="font-black">{{ money(detail.purchase_price) }}</div>
                    </div>
                    <div class="rounded-2xl border border-white/10 p-3">
                        <div class="text-[10px] uppercase tracking-widest text-white/30 font-black mb-1">Поставщик</div>
                        <div>{{ detail.supplier?.name || '—' }}</div>
                    </div>
                    <div class="rounded-2xl border border-white/10 p-3">
                        <div class="text-[10px] uppercase tracking-widest text-white/30 font-black mb-1">Кто принял</div>
                        <div>{{ detail.receiver?.name || '—' }}</div>
                    </div>
                    <div class="rounded-2xl border border-white/10 p-3">
                        <div class="text-[10px] uppercase tracking-widest text-white/30 font-black mb-1">Заказ поставщика</div>
                        <div class="font-mono">{{ detail.external_order_id ? ('EXT ' + detail.external_order_id) : '—' }}</div>
                        <div v-if="detail.purchase_id" class="text-white/30 text-[10px] mt-1 uppercase tracking-widest">
                            закупка #{{ detail.purchase_id }}
                            <span v-if="detail.estimate_id"> · смета #{{ detail.estimate_id }}</span>
                        </div>
                    </div>
                    <div class="rounded-2xl border border-white/10 p-3">
                        <div class="text-[10px] uppercase tracking-widest text-white/30 font-black mb-1">SKU поставщика</div>
                        <div class="font-mono">{{ detail.supplier_sku || '—' }}</div>
                    </div>
                    <div class="rounded-2xl border border-white/10 p-3">
                        <div class="text-[10px] uppercase tracking-widest text-white/30 font-black mb-1">Поступление</div>
                        <div>{{ fmtDt(detail.received_at) }}</div>
                    </div>
                    <div class="rounded-2xl border border-white/10 p-3">
                        <div class="text-[10px] uppercase tracking-widest text-white/30 font-black mb-1">Срок гарантии</div>
                        <div>{{ detail.warranty_months ? detail.warranty_months + ' мес.' : '—' }}</div>
                    </div>
                </div>

                <div v-if="detail.warranty_label"
                     class="rounded-2xl border px-4 py-3 text-xs font-black uppercase tracking-wider"
                     :class="{
                         'border-red-500/40 bg-red-500/10 text-red-300': detail.warranty_state === 'expired',
                         'border-amber-500/40 bg-amber-500/10 text-amber-300': detail.warranty_state === 'expiring',
                         'border-emerald-500/30 bg-emerald-500/10 text-emerald-300': detail.warranty_state === 'active',
                     }">
                    {{ detail.warranty_label }}
                    <span v-if="detail.warranty_ends_at" class="block mt-1 text-[10px] font-normal normal-case tracking-normal text-white/40">
                        до {{ fmtDate(detail.warranty_ends_at) }}
                    </span>
                </div>

                <div v-if="detail.sale" class="rounded-2xl border border-white/10 p-4 space-y-2 text-xs">
                    <div class="text-[10px] uppercase tracking-widest text-amber-400/80 font-black">Продажа</div>
                    <div class="flex justify-between gap-3"><span class="text-white/35">Клиент</span><span class="text-right">{{ detail.sale.client_name || '—' }}</span></div>
                    <div v-if="detail.sale.client_phone" class="flex justify-between gap-3"><span class="text-white/35">Телефон</span><span class="text-right">{{ detail.sale.client_phone }}</span></div>
                    <div class="flex justify-between gap-3"><span class="text-white/35">Кто продал</span><span class="text-right">{{ detail.sale.sold_by || '—' }}</span></div>
                    <div class="flex justify-between gap-3"><span class="text-white/35">Продано</span><span class="text-right">{{ fmtDt(detail.sale.sold_at) }}</span></div>
                    <div v-if="detail.sale.built_pc_title" class="flex justify-between gap-3">
                        <span class="text-white/35">Сборка</span>
                        <span class="text-right">{{ detail.sale.built_pc_title }}</span>
                    </div>
                    <div v-if="detail.sale.order_id" class="flex justify-between gap-3"><span class="text-white/35">Заказ</span><span class="text-right">#{{ detail.sale.order_id }}</span></div>
                </div>

                <div v-if="detail.sent_to_repair_label"
                     class="rounded-2xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-xs font-black uppercase tracking-wider text-amber-300">
                    {{ detail.sent_to_repair_label }}
                </div>
                <div v-if="detail.replaces_component_id || detail.replaced_by_component_id" class="text-xs text-white/40 space-y-1">
                    <div v-if="detail.replaces_component_id">Замена ID {{ detail.replaces_component_id }}</div>
                    <div v-if="detail.replaced_by_component_id">Заменена на ID {{ detail.replaced_by_component_id }}</div>
                </div>

                <div v-if="detail.notes" class="text-xs text-white/40">
                    <div class="text-[10px] uppercase tracking-widest text-white/30 font-black mb-1">Заметки</div>
                    {{ detail.notes }}
                </div>

                <div class="flex gap-3 justify-end pt-1">
                    <button v-if="canEditRow(detail)" type="button"
                            class="px-4 py-3 text-[10px] uppercase font-black text-amber-400"
                            @click="openEdit(detail)">Edit</button>
                    <button type="button" class="px-5 py-3 bg-white/5 border border-white/10 rounded-xl text-[10px] uppercase font-black"
                            @click="detail = null">Закрыть</button>
                </div>
            </div>
        </div>

        <div v-if="showForm" class="fixed inset-0 bg-black/70 flex items-start justify-center z-50 p-4 overflow-y-auto" @click.self="showForm = false">
            <form class="bg-[#0a0a0a] border border-white/10 rounded-3xl p-8 w-full max-w-xl space-y-4 my-8" @submit.prevent="save">
                <h3 class="font-black uppercase italic text-xl">{{ form.id ? 'Редактировать' : 'Приход комплектующего' }}</h3>

                <div class="space-y-2">
                    <label :class="labelClass">
                        {{ serialSlotCount > 1 ? `Серийные номера планок (${serialSlotCount})` : 'Серийный номер' }}
                    </label>
                    <input
                        v-for="(_, idx) in form.serials"
                        :key="'sn-' + idx"
                        :ref="(el) => setSerialRef(el, idx)"
                        v-model="form.serials[idx]"
                        data-scan-capture
                        :placeholder="serialSlotCount > 1 ? `Планка ${idx + 1}` : 'Сканер подставит сюда'"
                        :class="fieldClass + ' text-cyan-400 border-cyan-500/40'"
                        @input="(e) => onSerialInput(idx, e)"
                    />
                    <p v-if="form.type === 'ram'" class="text-[10px] text-white/30 uppercase tracking-widest">
                        Комплект 2×16 = одна позиция, два серийника. Сканер заполняет пустые поля по очереди.
                    </p>
                </div>

                <div>
                    <label :class="labelClass">Тип комплектующего</label>
                    <select v-model="form.type" :class="fieldClass" required>
                        <option v-for="(label, key) in types" :key="key" :value="key">{{ label }}</option>
                    </select>
                </div>

                <div class="rounded-2xl border border-white/10 p-4 space-y-3">
                    <div class="text-[10px] uppercase tracking-widest text-amber-400/80 font-black">Конструктор названия</div>
                    <div class="grid md:grid-cols-2 gap-3">
                        <SuggestInput
                            v-for="field in activeFields"
                            :key="form.type + '-' + field.key"
                            :model-value="specs[field.key] || ''"
                            :label="field.label"
                            :suggestions="suggestionsFor(field)"
                            :input-class="fieldClass"
                            :label-class="labelClass"
                            :min-chars="['socket', 'modules', 'ddr', 'brand'].includes(field.key) ? 1 : 3"
                            @update:model-value="(v) => onSpecUpdate(field.key, v)"
                            @search="(q) => onSuggestSearch(field, q)"
                        />
                    </div>
                    <div class="text-xs text-white/50 pt-1">
                        Итог:
                        <span class="text-amber-300 font-black uppercase ml-2">{{ composedName || '—' }}</span>
                    </div>
                </div>

                <div>
                    <label :class="labelClass">Название конструктора (можно поправить вручную)</label>
                    <input v-model="form.name" :placeholder="composedName || 'Соберётся из полей'" :class="fieldClass" @input="onNameInput" />
                </div>

                <div>
                    <label :class="labelClass">Поставщик</label>
                    <select v-model="form.store_supplier_id" :class="fieldClass">
                        <option :value="null">Не указан</option>
                        <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                </div>

                <div>
                    <label :class="labelClass">Цена закупки, ₽</label>
                    <input v-model.number="form.purchase_price" type="number" min="0" step="0.01" :class="fieldClass" required />
                </div>

                <div>
                    <label :class="labelClass">Срок гарантии, мес.</label>
                    <input v-model.number="form.warranty_months" type="number" min="0" :class="fieldClass" />
                </div>

                <div v-if="form.id">
                    <label :class="labelClass">Статус</label>
                    <select v-model="form.status" :class="fieldClass">
                        <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
                    </select>
                </div>

                <div>
                    <label :class="labelClass">Заметки</label>
                    <textarea v-model="form.notes" :class="fieldClass" rows="2" />
                </div>

                <div class="flex gap-3 justify-end pt-2">
                    <button type="button" class="px-4 py-3 text-[10px] uppercase font-black text-white/40" @click="showForm = false">Отмена</button>
                    <button class="px-6 py-3 bg-amber-500 text-black text-[10px] uppercase font-black rounded-xl" :disabled="form.processing">Сохранить</button>
                </div>
            </form>
        </div>

        <div v-if="showSupplier" class="fixed inset-0 bg-black/70 flex items-start justify-center z-50 p-4 overflow-y-auto" @click.self="showSupplier = false">
            <form class="bg-[#0a0a0a] border border-white/10 rounded-3xl p-8 w-full max-w-md space-y-4 my-8" @submit.prevent="saveSupplier">
                <h3 class="font-black uppercase italic text-xl">Поставщик магазина</h3>
                <div>
                    <label :class="labelClass">Название</label>
                    <input v-model="supplierForm.name" :class="fieldClass" required />
                </div>
                <div>
                    <label :class="labelClass">Телефон</label>
                    <input v-model="supplierForm.phone" :class="fieldClass" />
                </div>
                <div>
                    <label :class="labelClass">Заметки</label>
                    <input v-model="supplierForm.notes" :class="fieldClass" />
                </div>
                <div class="flex gap-3 justify-end">
                    <button type="button" class="px-4 py-3 text-[10px] uppercase font-black text-white/40" @click="showSupplier = false">Отмена</button>
                    <button class="px-6 py-3 bg-amber-500 text-black text-[10px] uppercase font-black rounded-xl" :disabled="supplierForm.processing">Добавить</button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
