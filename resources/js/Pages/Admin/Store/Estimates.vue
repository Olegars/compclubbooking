<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

type EstimateLine = {
    id?: number | null
    type: string | null
    name: string
    part: string
    supplier_sku: number | null
    supplier_part: string
    supplier_name: string
    supplier_price: number | null
    sale_price: number | null
    qty: number
    status: string
    store_component_id: number | null
    notes: string
    image_url: string | null
}

const props = defineProps<{
    estimates: any[]
    clients: any[]
    components: any[]
    componentTypes: Record<string, string>
    statuses: string[]
    statusLabels: Record<string, string>
    itemStatusLabels: Record<string, string>
    filters: { status?: string | null }
    canManage: boolean
    quickfoxConfigured: boolean
    catalogStats: { products: number, categories: number, priced?: number, synced_at?: string | null, price_synced_at?: string | null }
}>()

const page = usePage()
const flashSuccess = computed(() => (page.props as any).flash?.success || '')
const flashError = computed(() => (page.props as any).flash?.error || '')

const money = (v: any) => Number(v || 0).toLocaleString('ru-RU') + ' ₽'
const fmtTs = (v: any): string => {
    if (v == null || v === '') return ''
    if (typeof v === 'string') return v.slice(0, 16).replace('T', ' ')
    if (typeof v === 'object' && typeof v.date === 'string') return String(v.date).slice(0, 16).replace('T', ' ')
    return ''
}

const expanded = ref<Record<number, boolean>>({})
const isOpen = (id: number) => !!expanded.value[id]
const toggle = (id: number) => { expanded.value = { ...expanded.value, [id]: !expanded.value[id] } }

const showForm = ref(false)
const editingId = ref<number | null>(null)
const submitting = ref(false)

const form = useForm({
    store_client_id: null as number | null,
    title: '',
    notes: '',
    items: [] as EstimateLine[],
})

const emptyLine = (): EstimateLine => ({
    id: null,
    type: null,
    name: '',
    part: '',
    supplier_sku: null,
    supplier_part: '',
    supplier_name: '',
    supplier_price: null,
    sale_price: null,
    qty: 1,
    status: 'planned',
    store_component_id: null,
    notes: '',
    image_url: null,
})

const addLine = () => form.items.push(emptyLine())
const removeLine = (i: number) => {
    const line = form.items[i]
    if (line && lineLocked(line)) return
    if (form.items.length > 1) form.items.splice(i, 1)
}

/** Уже сохранённые позиции со склада / заказа — нельзя менять в форме */
const lineLocked = (line: EstimateLine) =>
    !!line.id && ['ordered', 'received', 'from_stock'].includes(line.status)

const lineFilled = (line: EstimateLine) =>
    !!(line.name?.trim() || line.supplier_sku || line.store_component_id)

const clearLine = (i: number) => {
    const line = form.items[i]
    if (!line || lineLocked(line)) return
    const id = line.id ?? null
    Object.assign(line, emptyLine(), { id })
}

const openCreate = () => {
    editingId.value = null
    form.reset()
    form.store_client_id = null
    form.title = ''
    form.notes = ''
    form.items = [emptyLine()]
    closePicker()
    showForm.value = true
}

const openEdit = (est: any) => {
    editingId.value = est.id
    form.store_client_id = est.store_client_id
    form.title = est.title || ''
    form.notes = est.notes || ''
    form.items = (est.items || []).length
        ? est.items.map((item: any) => ({
            id: item.id,
            type: item.type || null,
            name: item.name || '',
            part: item.part || '',
            supplier_sku: item.supplier_sku,
            supplier_part: item.supplier_part || '',
            supplier_name: item.supplier_name || '',
            supplier_price: item.supplier_price != null ? Number(item.supplier_price) : null,
            sale_price: item.sale_price != null ? Number(item.sale_price) : null,
            qty: item.qty || 1,
            status: item.status || 'planned',
            store_component_id: item.store_component_id,
            notes: item.notes || '',
            image_url: item.supplier_image_url || null,
        }))
        : [emptyLine()]
    closePicker()
    showForm.value = true
}

const closeForm = () => {
    closePicker()
    showForm.value = false
    editingId.value = null
}

const save = () => {
    const items = form.items.filter(l => l.name.trim())
    if (!items.length) return
    submitting.value = true
    const payload = {
        store_client_id: form.store_client_id,
        title: form.title,
        notes: form.notes,
        items,
    }
    const opts = {
        onFinish: () => { submitting.value = false },
        onSuccess: () => {
            closeForm()
            form.reset()
            form.items = [emptyLine()]
        },
    }
    if (editingId.value) {
        router.put(`/admin/store/estimates/${editingId.value}`, payload, opts)
    } else {
        router.post('/admin/store/estimates', payload, opts)
    }
}

const setStatus = (id: number, status: string) => {
    router.post(`/admin/store/estimates/${id}/status`, { status }, { preserveScroll: true })
}

const checkSupplier = (id: number) => {
    router.post(`/admin/store/estimates/${id}/check-supplier`, {}, { preserveScroll: true })
}

const orderMissing = (id: number, confirmShip = false) => {
    const msg = confirmShip
        ? 'Создать заказ у поставщика и сразу подписать на отгрузку?'
        : 'Создать заказ у поставщика по недостающим позициям?'
    if (!confirm(msg)) return
    router.post(`/admin/store/estimates/${id}/order-missing`, { confirm: confirmShip }, { preserveScroll: true })
}

const receivePurchase = (purchaseId: number) => {
    if (!confirm('Принять закупку на склад (резерв под смету)?')) return
    router.post(`/admin/store/purchases/${purchaseId}/receive`, {}, { preserveScroll: true })
}

const convert = (id: number) => {
    if (!confirm('Создать заказ магазина из сметы? Комплектующие будут проданы.')) return
    router.post(`/admin/store/estimates/${id}/convert`, {}, { preserveScroll: true })
}

const linkStock = (itemId: number, componentId: string) => {
    if (!componentId) return
    router.post(`/admin/store/estimate-items/${itemId}/link-stock`, {
        store_component_id: Number(componentId),
    }, { preserveScroll: true })
}

const unlinkStock = (itemId: number) => {
    router.post(`/admin/store/estimate-items/${itemId}/unlink-stock`, {}, { preserveScroll: true })
}

const filterStatus = computed({
    get: () => props.filters?.status || '',
    set: (v: string) => router.get('/admin/store/estimates', v ? { status: v } : {}, { preserveState: true }),
})

const formSaleTotal = computed(() =>
    form.items.reduce((sum, line) => {
        if (!lineFilled(line) || line.sale_price == null) return sum
        return sum + Number(line.sale_price) * Math.max(1, Number(line.qty) || 1)
    }, 0)
)
const formPurchaseTotal = computed(() =>
    form.items.reduce((sum, line) => {
        if (!lineFilled(line) || line.supplier_price == null) return sum
        return sum + Number(line.supplier_price) * Math.max(1, Number(line.qty) || 1)
    }, 0)
)

// --- picker (каталог / склад) ---
const pickerMode = ref<'catalog' | 'stock' | null>(null)
const pickerLineIndex = ref<number | null>(null)
const pickerType = ref<string | null>(null)
const searchQ = ref('')
const searchResults = ref<any[]>([])
const searchMeta = ref<{ type?: string | null, type_filter_empty?: boolean, count?: number }>({})
const searchTimer = ref<any>(null)
const includeOos = ref(false)

const closePicker = () => {
    pickerMode.value = null
    pickerLineIndex.value = null
    pickerType.value = null
    searchQ.value = ''
    searchResults.value = []
    searchMeta.value = {}
    includeOos.value = false
    clearTimeout(searchTimer.value)
}

const openPicker = (mode: 'catalog' | 'stock', index: number) => {
    const line = form.items[index]
    if (!line || lineLocked(line)) return
    pickerMode.value = mode
    pickerLineIndex.value = index
    pickerType.value = line.type || null
    searchResults.value = []
    searchMeta.value = {}
    includeOos.value = false
    // Вентиляторы: сразу 120мм
    if (String(pickerType.value || '').toLowerCase() === 'fan') {
        searchQ.value = '120мм'
    } else {
        searchQ.value = ''
    }
    if (mode === 'catalog' && searchQ.value.trim().length >= 2) {
        clearTimeout(searchTimer.value)
        runCatalogSearch()
    }
}

const setPickerType = (type: string | null) => {
    pickerType.value = type
    if (String(type || '').toLowerCase() === 'fan') {
        searchQ.value = '120мм'
    }
}

const runCatalogSearch = async () => {
    if (pickerMode.value !== 'catalog') return
    const q = searchQ.value
    if (!q || q.trim().length < 2) {
        searchResults.value = []
        searchMeta.value = {}
        return
    }
    try {
        const type = pickerType.value || ''
        const params = new URLSearchParams({ q: q.trim() })
        if (type) params.set('type', type)
        if (includeOos.value) params.set('include_oos', '1')
        const res = await fetch(`/admin/store/estimates/catalog-search?${params}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
        const json = await res.json()
        searchResults.value = json.products || []
        searchMeta.value = json.meta || {}
    } catch {
        searchResults.value = []
        searchMeta.value = {}
    }
}

watch(searchQ, () => {
    if (pickerMode.value !== 'catalog') return
    clearTimeout(searchTimer.value)
    searchTimer.value = setTimeout(runCatalogSearch, 300)
})

watch(includeOos, () => {
    if (pickerMode.value === 'catalog') runCatalogSearch()
})

watch(pickerType, () => {
    if (pickerMode.value === 'catalog') {
        clearTimeout(searchTimer.value)
        searchTimer.value = setTimeout(runCatalogSearch, 150)
    }
})

const stockResults = computed(() => {
    if (pickerMode.value !== 'stock') return [] as any[]
    const q = searchQ.value.trim().toLowerCase()
    const type = pickerType.value
    const usedIds = new Set(
        form.items
            .map((l, idx) => (idx !== pickerLineIndex.value ? l.store_component_id : null))
            .filter((id): id is number => id != null && id > 0),
    )
    return (props.components || []).filter((c: any) => {
        if (usedIds.has(c.id)) return false
        if (type && c.type !== type) return false
        if (q.length >= 1) {
            const hay = `${c.name || ''} ${c.warranty_number || ''} ${c.id}`.toLowerCase()
            if (!hay.includes(q)) return false
        }
        return true
    })
})

/** Быстрые фильтры по типу в пикере */
const quickChips = computed(() => {
    switch (String(pickerType.value || '').toLowerCase()) {
        case 'ram':
            return [
                { id: 'ddr5', label: 'DDR5', token: 'DDR5' },
                { id: 'ddr4', label: 'DDR4', token: 'DDR4' },
                { id: '16gb', label: '16GB', token: '16GB' },
                { id: '32gb', label: '32GB', token: '32GB' },
                { id: '64gb', label: '64GB', token: '64GB' },
            ]
        case 'cpu':
            return [
                { id: 'lga1700', label: 'LGA1700', token: '1700' },
                { id: 'am5', label: 'AM5', token: 'AM5' },
                { id: 'am4', label: 'AM4', token: 'AM4' },
            ]
        case 'motherboard':
            return [
                { id: 'am4', label: 'AM4', token: 'AM4' },
                { id: 'am5', label: 'AM5', token: 'AM5' },
                { id: '1700', label: '1700', token: '1700' },
                { id: '1851', label: '1851', token: '1851' },
            ]
        case 'gpu':
            return [
                { id: '5050', label: '5050', token: '5050' },
                { id: '5060', label: '5060', token: '5060' },
                { id: '5070', label: '5070', token: '5070' },
                { id: '5080', label: '5080', token: '5080' },
            ]
        case 'storage_ssd':
            return [
                { id: '256gb', label: '256GB', token: '256GB' },
                { id: '500gb', label: '500GB', token: '500GB' },
                { id: '1000gb', label: '1000GB', token: '1000GB' },
                { id: '2000gb', label: '2000GB', token: '2000GB' },
                { id: '4000gb', label: '4000GB', token: '4000GB' },
            ]
        case 'storage_hdd':
            return [
                { id: '2tb', label: '2TB', token: '2TB' },
                { id: '4tb', label: '4TB', token: '4TB' },
                { id: '6tb', label: '6TB', token: '6TB' },
                { id: '8tb', label: '8TB', token: '8TB' },
            ]
        case 'psu':
            return [
                { id: '500w', label: '500Вт', token: '500Вт' },
                { id: '600w', label: '600Вт', token: '600Вт' },
                { id: '700w', label: '700Вт', token: '700Вт' },
                { id: '800w', label: '800Вт', token: '800Вт' },
                { id: '1000w', label: '1000Вт', token: '1000Вт' },
            ]
        case 'fan':
            return [
                { id: '120mm', label: '120мм CPU', token: '120мм' },
            ]
        case 'case':
            return [
                { id: 'white', label: 'белый', token: 'белый' },
                { id: 'black', label: 'черный', token: 'черный' },
                { id: 'side-glass', label: 'стекло с боку', token: 'боковое' },
                { id: 'front-side-glass', label: 'стекло спереди и сбоку', token: 'frontglass' },
                { id: 'atx', label: 'ATX', token: 'ATX' },
            ]
        default:
            return []
    }
})

const chipActive = (token: string) => {
    const parts = searchQ.value.toLowerCase().split(/\s+/).filter(Boolean)
    return parts.includes(token.toLowerCase())
}

const toggleChip = (token: string) => {
    const raw = searchQ.value.trim()
    const parts = raw === '' ? [] : raw.split(/\s+/).filter(Boolean)
    const lower = token.toLowerCase()
    const exclusiveGroups = [
        ['ddr4', 'ddr5'],
        ['16gb', '32gb', '64gb'],
        ['am4', 'am5', '1700', '1851'],
        ['5050', '5060', '5070', '5080'],
        ['256gb', '500gb', '1000gb', '2000gb', '4000gb'],
        ['2tb', '4tb', '6tb', '8tb'],
        ['500вт', '600вт', '700вт', '800вт', '1000вт', '500w', '600w', '700w', '800w', '1000w'],
        ['120мм', '120mm'],
        ['белый', 'черный', 'белый', 'чёрный'],
        ['боковое', 'frontglass'],
    ]
    const tokenLower = lower
    let next = [...parts]
    const isOn = next.some(p => p.toLowerCase() === tokenLower)
    if (isOn) {
        next = next.filter(p => p.toLowerCase() !== tokenLower)
    } else {
        for (const group of exclusiveGroups) {
            if (group.includes(tokenLower)) {
                next = next.filter(p => !group.includes(p.toLowerCase()))
            }
        }
        next.push(token)
    }
    searchQ.value = next.join(' ')
}

const pickProduct = (p: any) => {
    if (pickerLineIndex.value === null) return
    const line = form.items[pickerLineIndex.value]
    if (!line || lineLocked(line)) return
    if (pickerType.value) line.type = pickerType.value
    line.name = p.name
    line.part = p.part || ''
    line.supplier_sku = p.sku
    line.supplier_part = p.part || ''
    line.supplier_name = p.name
    line.supplier_price = p.price != null ? Number(p.price) : null
    line.sale_price = p.price != null ? Number(p.price) : null
    line.status = 'to_order'
    line.store_component_id = null
    line.image_url = p.image_url || null
    closePicker()
}

const pickStock = (c: any) => {
    if (pickerLineIndex.value === null) return
    const line = form.items[pickerLineIndex.value]
    if (!line || lineLocked(line)) return
    line.type = c.type || pickerType.value
    line.name = c.name
    line.part = ''
    line.supplier_sku = null
    line.supplier_part = ''
    line.supplier_name = ''
    line.supplier_price = c.purchase_price != null ? Number(c.purchase_price) : null
    line.sale_price = c.sale_price != null
        ? Number(c.sale_price)
        : (c.purchase_price != null ? Number(c.purchase_price) : null)
    line.status = 'from_stock'
    line.store_component_id = c.id
    line.image_url = c.image_url || null
    closePicker()
}

const lightbox = ref<{ url: string, title: string } | null>(null)
const imageSized = (url: string, size: 'medium' | 'large' | 'original' = 'large') => {
    try {
        const u = new URL(url, window.location.origin)
        u.searchParams.set('size', size)
        return u.pathname + u.search
    } catch {
        return url
    }
}
const openLightbox = (url: string | null | undefined, title = '') => {
    if (!url) return
    lightbox.value = { url: imageSized(url, 'large'), title }
}
const closeLightbox = () => { lightbox.value = null }

const onEscKey = (e: KeyboardEvent) => {
    if (e.key !== 'Escape') return
    if (lightbox.value) {
        closeLightbox()
        return
    }
    if (pickerMode.value) {
        closePicker()
    }
}
onMounted(() => window.addEventListener('keydown', onEscKey))
onUnmounted(() => window.removeEventListener('keydown', onEscKey))

const canEditEstimate = (est: any) =>
    props.canManage && ['draft', 'agreed', 'procuring', 'ready'].includes(est.status)

const canEditItems = (est: any) =>
    props.canManage && ['draft', 'agreed'].includes(est.status)

const stockOptionsFor = (item: any) => {
    return (props.components || []).filter(c => {
        if (item.type && c.type !== item.type) return false
        return true
    })
}
</script>

<template>
    <Head title="REACTOR | Сметы" />
    <AdminLayout>
        <div class="max-w-7xl mx-auto space-y-8 pb-20 px-4 font-mono">
            <div class="flex flex-wrap justify-between items-end gap-4 border-b border-white/10 pb-6">
                <div>
                    <h1 class="text-3xl font-black uppercase italic tracking-tighter">Store <span class="text-amber-400">Estimates</span></h1>
                    <p class="text-white/20 text-[10px] uppercase tracking-[0.4em] font-black mt-2 italic">
                        Смета → закупка API → склад → заказ
                    </p>
                </div>
                <div class="flex flex-wrap gap-3 items-center">
                    <select v-model="filterStatus" class="bg-black border border-white/10 rounded-xl px-4 py-3 text-[10px] uppercase font-black">
                        <option value="">Все статусы</option>
                        <option v-for="s in statuses" :key="s" :value="s">{{ statusLabels[s] || s }}</option>
                    </select>
                    <button v-if="canManage" type="button" @click="openCreate"
                            class="px-6 py-4 bg-amber-500 text-black font-black uppercase tracking-widest text-[10px] rounded-2xl">
                        + Смета
                    </button>
                </div>
            </div>

            <div v-if="flashSuccess" class="text-emerald-400 text-xs uppercase tracking-widest font-black">{{ flashSuccess }}</div>
            <div v-if="flashError" class="text-red-400 text-xs uppercase tracking-widest font-black">{{ flashError }}</div>

            <div class="text-[10px] text-white/30 uppercase tracking-widest">
                Каталог API:
                <span v-if="quickfoxConfigured" class="text-white/50">
                    {{ catalogStats.products }} тов. / {{ catalogStats.categories }} кат.
                    · с ценой {{ catalogStats.priced ?? 0 }}
                    <span v-if="fmtTs(catalogStats.synced_at)"> · {{ fmtTs(catalogStats.synced_at) }}</span>
                    <span v-if="fmtTs(catalogStats.price_synced_at)"> · цены {{ fmtTs(catalogStats.price_synced_at) }}</span>
                </span>
                <span v-else class="text-orange-400/80">не настроен (STORE_QUICKFOX_*)</span>
            </div>

            <div class="space-y-3">
                <div v-for="est in estimates" :key="est.id" class="border border-white/5 bg-[#080808] rounded-2xl overflow-hidden">
                    <button type="button"
                            class="w-full text-left p-5 flex flex-wrap justify-between gap-3 hover:bg-white/[0.02]"
                            @click="toggle(est.id)">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="text-white/30 text-sm font-black w-4 shrink-0">{{ isOpen(est.id) ? '▾' : '▸' }}</span>
                                <span class="font-black uppercase">#{{ est.id }} · {{ statusLabels[est.status] || est.status }}</span>
                                <span class="text-[10px] text-white/25 uppercase tracking-widest">{{ (est.items || []).length }} поз.</span>
                            </div>
                            <div class="text-[10px] text-white/30 uppercase tracking-widest mt-1 pl-6">
                                {{ est.title || 'Без названия' }}
                                · {{ est.client?.name || 'Без клиента' }}
                                <span v-if="est.store_order_id"> · заказ #{{ est.store_order_id }}</span>
                            </div>
                        </div>
                        <div class="text-right shrink-0 self-start space-y-1">
                            <div class="font-black text-amber-400">{{ money(est.sale_total) }}</div>
                            <div class="text-[10px] text-white/30">закуп {{ money(est.purchase_total) }}</div>
                        </div>
                    </button>

                    <div v-show="isOpen(est.id)" class="px-5 pb-5 space-y-4 border-t border-white/5 pt-4">
                        <div class="space-y-2">
                            <div v-for="item in est.items" :key="item.id"
                                 class="grid grid-cols-1 md:grid-cols-[auto_1fr_auto] gap-3 text-xs text-white/50 border border-white/5 rounded-xl p-3">
                                <button v-if="item.supplier_image_url" type="button"
                                        class="w-14 h-14 shrink-0 rounded-xl bg-black/60 border border-white/10 overflow-hidden hover:border-amber-500/40"
                                        title="Увеличить"
                                        @click="openLightbox(item.supplier_image_url, item.name)">
                                    <img :src="item.supplier_image_url" :alt="item.name" class="w-full h-full object-contain" loading="lazy" />
                                </button>
                                <div v-else class="w-14 h-14 shrink-0 rounded-xl bg-black/40 border border-white/5 hidden md:block" />
                                <div>
                                    <div class="text-white/80 font-black uppercase tracking-wide text-[11px]">
                                        {{ item.name }}
                                        <span class="text-white/30 font-normal"> · {{ itemStatusLabels[item.status] || item.status }}</span>
                                    </div>
                                    <div class="text-[10px] text-white/30 mt-1 uppercase tracking-widest">
                                        <span v-if="item.type">{{ componentTypes[item.type] || item.type }} · </span>
                                        <span v-if="item.supplier_sku">SKU {{ item.supplier_sku }}</span>
                                        <span v-if="item.supplier_part"> · {{ item.supplier_part }}</span>
                                        <span v-if="item.supplier_price != null"> · {{ money(item.supplier_price) }}</span>
                                        <span v-if="item.supplier_qty != null"> · ост. {{ item.supplier_qty }}</span>
                                    </div>
                                    <div v-if="item.component" class="text-[10px] text-cyan-400/80 mt-1">
                                        Склад #{{ item.component.id }} · {{ item.component.name }}
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-2 items-start justify-end">
                                    <template v-if="canManage && ['planned', 'to_order'].includes(item.status)">
                                        <select class="bg-black border border-white/10 rounded-lg px-2 py-1 text-[10px] max-w-[220px]"
                                                @change="linkStock(item.id, ($event.target as HTMLSelectElement).value); ($event.target as HTMLSelectElement).value = ''">
                                            <option value="">Со склада…</option>
                                            <option v-for="c in stockOptionsFor(item)" :key="c.id" :value="c.id">
                                                {{ c.name }} · {{ money(c.purchase_price) }}
                                            </option>
                                        </select>
                                    </template>
                                    <button v-if="canManage && item.status === 'from_stock'" type="button"
                                            class="text-[10px] uppercase font-black text-red-400/80"
                                            @click="unlinkStock(item.id)">
                                        Снять резерв
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div v-if="(est.purchases || []).length" class="space-y-2">
                            <div class="text-[10px] uppercase font-black text-white/30 tracking-widest">Закупки API</div>
                            <div v-for="p in est.purchases" :key="p.id"
                                 class="flex flex-wrap items-center gap-3 text-[10px] uppercase tracking-widest border border-white/5 rounded-xl px-3 py-2">
                                <span>#{{ p.id }} · ext {{ p.external_order_id || '—' }} · {{ p.status }} · {{ money(p.total) }}</span>
                                <button v-if="canManage && ['submitted', 'confirmed'].includes(p.status)" type="button"
                                        class="px-3 py-1 rounded-lg border border-emerald-500/30 text-emerald-400 font-black"
                                        @click="receivePurchase(p.id)">
                                    Принять на склад
                                </button>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2 items-center">
                            <button v-if="canManage && est.status === 'draft'" type="button"
                                    class="px-3 py-2 rounded-xl border border-amber-500/40 text-[10px] uppercase font-black text-amber-400"
                                    @click="setStatus(est.id, 'agreed')">
                                Согласовать
                            </button>
                            <button v-if="canManage && quickfoxConfigured && ['agreed', 'procuring', 'ready', 'draft'].includes(est.status)" type="button"
                                    class="px-3 py-2 rounded-xl border border-white/20 text-[10px] uppercase font-black text-white/70"
                                    @click="checkSupplier(est.id)">
                                Цены API
                            </button>
                            <button v-if="canManage && quickfoxConfigured && ['agreed', 'procuring', 'ready'].includes(est.status)" type="button"
                                    class="px-3 py-2 rounded-xl border border-cyan-500/30 text-[10px] uppercase font-black text-cyan-400"
                                    @click="orderMissing(est.id, false)">
                                Заказать недостающее
                            </button>
                            <button v-if="canManage && est.status === 'ready'" type="button"
                                    class="px-3 py-2 rounded-xl bg-amber-500 text-black text-[10px] uppercase font-black"
                                    @click="convert(est.id)">
                                В заказ магазина
                            </button>
                            <button v-if="canEditItems(est)" type="button"
                                    class="px-3 py-2 text-[10px] uppercase font-black text-amber-400/80"
                                    @click="openEdit(est)">
                                Edit
                            </button>
                            <button v-if="canManage && !['converted', 'cancelled'].includes(est.status)" type="button"
                                    class="px-3 py-2 rounded-xl border border-red-500/20 text-[10px] uppercase font-black text-red-400"
                                    @click="setStatus(est.id, 'cancelled')">
                                Отмена
                            </button>
                        </div>
                    </div>
                </div>
                <div v-if="!estimates.length" class="text-white/30 text-sm py-10 text-center">Смет пока нет</div>
            </div>
        </div>

        <!-- форма создания / редактирования -->
        <div v-if="showForm" class="fixed inset-0 bg-black/70 flex items-start justify-center z-50 p-4 overflow-y-auto" @click.self="closeForm">
            <form class="bg-[#0a0a0a] border border-white/10 rounded-3xl p-8 w-full max-w-3xl space-y-4 my-8" @submit.prevent="save">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <h3 class="font-black uppercase italic text-xl">
                        {{ editingId ? `Смета #${editingId}` : 'Новая смета' }}
                    </h3>
                    <div class="text-right shrink-0">
                        <div class="font-black text-amber-400 text-xl">{{ money(formSaleTotal) }}</div>
                        <div class="text-[10px] text-white/30 uppercase tracking-widest">закуп {{ money(formPurchaseTotal) }}</div>
                    </div>
                </div>
                <input v-model="form.title" placeholder="Название (сборка / клиент)"
                       class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />
                <select v-model="form.store_client_id" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm">
                    <option :value="null">Без клиента</option>
                    <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }} · {{ c.phone }}</option>
                </select>

                <div class="text-[10px] uppercase font-black text-white/30 tracking-widest">Комплектация</div>
                <div v-for="(line, i) in form.items" :key="i" class="border border-white/5 rounded-2xl p-3 space-y-3">
                    <!-- пустая строка -->
                    <div v-if="!lineFilled(line)" class="flex flex-wrap items-center gap-2">
                        <button type="button"
                                class="flex-1 min-w-[120px] px-4 py-3 rounded-xl border border-cyan-500/30 text-[10px] uppercase font-black tracking-widest text-cyan-400 hover:bg-cyan-500/10 disabled:opacity-40"
                                :disabled="lineLocked(line) || !catalogStats.products"
                                @click="openPicker('catalog', i)">
                            Каталог
                        </button>
                        <button type="button"
                                class="flex-1 min-w-[120px] px-4 py-3 rounded-xl border border-amber-500/30 text-[10px] uppercase font-black tracking-widest text-amber-400 hover:bg-amber-500/10 disabled:opacity-40"
                                :disabled="lineLocked(line)"
                                @click="openPicker('stock', i)">
                            Склад
                        </button>
                        <button type="button" class="text-red-400 text-xl leading-none px-2 disabled:opacity-40"
                                :disabled="lineLocked(line) || form.items.length <= 1"
                                @click="removeLine(i)">×</button>
                    </div>

                    <!-- заполненная строка (только просмотр) -->
                    <div v-else class="flex gap-3 items-start">
                        <button v-if="line.image_url" type="button"
                                class="w-14 h-14 shrink-0 rounded-xl bg-black/60 border border-white/10 overflow-hidden hover:border-amber-500/40"
                                title="Увеличить"
                                @click="openLightbox(line.image_url, line.name)">
                            <img :src="line.image_url" :alt="line.name" class="w-full h-full object-contain" loading="lazy" />
                        </button>
                        <div v-else class="w-14 h-14 shrink-0 rounded-xl bg-black/40 border border-white/5" />
                        <div class="min-w-0 flex-1 space-y-2">
                            <div class="text-white/90 font-black uppercase tracking-wide text-[12px]">
                                {{ line.name }}
                            </div>
                            <div class="text-[10px] text-white/35 uppercase tracking-widest">
                                <span v-if="line.type">{{ componentTypes[line.type] || line.type }}</span>
                                <span v-if="line.supplier_sku"> · SKU {{ line.supplier_sku }}</span>
                                <span v-else-if="line.store_component_id"> · Склад #{{ line.store_component_id }}</span>
                                <span v-if="line.part"> · {{ line.part }}</span>
                            </div>
                            <div class="text-[10px] text-white/40 uppercase tracking-widest">
                                <span v-if="line.supplier_price != null">закуп {{ money(line.supplier_price) }}</span>
                                <span v-if="line.sale_price != null"> · продажа {{ money(line.sale_price) }}</span>
                                <span> · {{ itemStatusLabels[line.status] || line.status }}</span>
                            </div>
                            <div v-if="!lineLocked(line)" class="flex flex-wrap gap-2 pt-1">
                                <button type="button"
                                        class="px-3 py-1.5 rounded-lg border border-cyan-500/25 text-[9px] uppercase font-black tracking-widest text-cyan-400/90 hover:bg-cyan-500/10 disabled:opacity-40"
                                        :disabled="!catalogStats.products"
                                        @click="openPicker('catalog', i)">
                                    Каталог
                                </button>
                                <button type="button"
                                        class="px-3 py-1.5 rounded-lg border border-amber-500/25 text-[9px] uppercase font-black tracking-widest text-amber-400/90 hover:bg-amber-500/10"
                                        @click="openPicker('stock', i)">
                                    Склад
                                </button>
                                <button type="button"
                                        class="px-3 py-1.5 rounded-lg border border-white/10 text-[9px] uppercase font-black tracking-widest text-white/50 hover:text-white/80"
                                        @click="clearLine(i)">
                                    Очистить
                                </button>
                                <button type="button"
                                        class="px-3 py-1.5 rounded-lg border border-red-500/20 text-[9px] uppercase font-black tracking-widest text-red-400/80"
                                        :disabled="form.items.length <= 1"
                                        @click="removeLine(i)">
                                    Удалить
                                </button>
                            </div>
                            <div v-else class="text-[9px] uppercase tracking-widest text-white/25 pt-1">
                                Позиция зафиксирована
                            </div>
                        </div>
                    </div>
                </div>
                <button type="button" class="text-[10px] uppercase font-black text-amber-400" @click="addLine">+ позиция</button>

                <textarea v-model="form.notes" placeholder="Заметки" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" rows="2" />
                <div class="flex gap-3 justify-end">
                    <button type="button" class="px-4 py-3 text-[10px] uppercase font-black text-white/40" @click="closeForm">Отмена</button>
                    <button class="px-6 py-3 bg-amber-500 text-black text-[10px] uppercase font-black rounded-xl" :disabled="submitting">
                        {{ editingId ? 'Сохранить' : 'Создать' }}
                    </button>
                </div>
            </form>
        </div>

        <!-- fullscreen picker -->
        <div v-if="pickerMode" class="fixed inset-0 z-[60] bg-[#050505] flex flex-col font-mono">
            <div class="shrink-0 border-b border-white/10 px-4 py-4 space-y-3">
                <div class="flex items-center justify-between gap-3">
                    <div class="text-[11px] uppercase font-black tracking-widest"
                         :class="pickerMode === 'catalog' ? 'text-cyan-400' : 'text-amber-400'">
                        {{ pickerMode === 'catalog' ? 'Каталог поставщика' : 'Склад' }}
                    </div>
                    <button type="button" class="text-white/40 hover:text-white text-2xl leading-none px-2" @click="closePicker">×</button>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button type="button"
                            class="px-3 py-1.5 rounded-xl border text-[9px] uppercase font-black tracking-widest"
                            :class="pickerType === null
                                ? 'border-amber-500/60 bg-amber-500/20 text-amber-300'
                                : 'border-white/15 text-white/50 hover:border-white/30'"
                            @click="setPickerType(null)">
                        Все типы
                    </button>
                    <button v-for="(label, key) in componentTypes" :key="key" type="button"
                            class="px-3 py-1.5 rounded-xl border text-[9px] uppercase font-black tracking-widest"
                            :class="pickerType === key
                                ? 'border-amber-500/60 bg-amber-500/20 text-amber-300'
                                : 'border-white/15 text-white/50 hover:border-white/30'"
                            @click="setPickerType(String(key))">
                        {{ label }}
                    </button>
                </div>

                <input v-model="searchQ"
                       :placeholder="pickerMode === 'catalog' ? '13400F / Ryzen / DDR5 32GB…' : 'Название / № склада…'"
                       class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm"
                       autofocus />

                <div v-if="pickerMode === 'catalog' && quickChips.length" class="flex flex-wrap gap-2">
                    <button v-for="chip in quickChips" :key="chip.id" type="button"
                            class="px-3 py-2 rounded-xl border text-[10px] uppercase font-black tracking-widest"
                            :class="chipActive(chip.token)
                                ? 'border-amber-500/60 bg-amber-500/20 text-amber-300'
                                : 'border-white/20 text-white/70 hover:border-amber-500/40 hover:text-amber-400'"
                            @click="toggleChip(chip.token)">
                        {{ chip.label }}
                    </button>
                </div>

                <label v-if="pickerMode === 'catalog'"
                       class="flex items-center gap-2 text-[10px] uppercase font-black text-white/40 tracking-widest cursor-pointer">
                    <input v-model="includeOos" type="checkbox" class="accent-amber-500" />
                    Показывать нет в наличии
                </label>
            </div>

            <div class="flex-1 overflow-y-auto px-4 py-3 space-y-1">
                <!-- результаты каталога -->
                <template v-if="pickerMode === 'catalog'">
                    <button v-for="p in searchResults" :key="p.sku" type="button"
                            class="w-full text-left px-3 py-3 rounded-xl hover:bg-white/5 text-xs border border-transparent hover:border-white/5"
                            @click="pickProduct(p)">
                        <div class="flex gap-3 items-start">
                            <div class="w-14 h-14 shrink-0 rounded-lg bg-black/60 border border-white/10 overflow-hidden flex items-center justify-center"
                                 :class="p.image_url ? 'cursor-zoom-in hover:border-amber-500/40' : ''"
                                 @click.stop="openLightbox(p.image_url, p.name)">
                                <img v-if="p.image_url" :src="p.image_url" :alt="p.name" class="w-full h-full object-contain" loading="lazy" />
                                <span v-else class="text-[9px] text-white/20 uppercase">нет</span>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex justify-between gap-3">
                                    <span class="text-white/85 min-w-0">{{ p.name }}</span>
                                    <span v-if="p.price != null" class="shrink-0 font-black text-amber-400">{{ money(p.price) }}</span>
                                    <span v-else class="shrink-0 text-white/25">нет в наличии</span>
                                </div>
                                <div class="text-white/30 mt-0.5 uppercase tracking-widest text-[10px]">
                                    {{ p.part || '—' }} · sku {{ p.sku }}
                                    <span v-if="p.stock_qty != null"> · ост. {{ p.stock_qty }}</span>
                                    <span v-if="p.category_name" class="text-cyan-400/50"> · {{ p.category_name }}</span>
                                </div>
                            </div>
                        </div>
                    </button>
                    <div v-if="searchQ.trim().length < 2" class="text-white/30 text-xs py-8 text-center uppercase tracking-widest">
                        Введите минимум 2 символа
                    </div>
                    <div v-else-if="!searchResults.length" class="text-white/30 text-xs py-8 text-center">
                        Ничего не найдено
                        <span v-if="searchMeta.type_filter_empty"> (категории для типа не сматчились — попробуйте другой запрос)</span>
                    </div>
                </template>

                <!-- результаты склада -->
                <template v-else>
                    <button v-for="c in stockResults" :key="c.id" type="button"
                            class="w-full text-left px-3 py-3 rounded-xl hover:bg-white/5 text-xs border border-transparent hover:border-white/5"
                            @click="pickStock(c)">
                        <div class="flex justify-between gap-3">
                            <div class="min-w-0">
                                <div class="text-white/85">{{ c.name }}</div>
                                <div class="text-white/30 mt-0.5 uppercase tracking-widest text-[10px]">
                                    #{{ c.id }}
                                    <span v-if="c.type"> · {{ componentTypes[c.type] || c.type }}</span>
                                    <span v-if="c.warranty_number"> · {{ c.warranty_number }}</span>
                                </div>
                            </div>
                            <span v-if="c.purchase_price != null" class="shrink-0 font-black text-amber-400">{{ money(c.purchase_price) }}</span>
                        </div>
                    </button>
                    <div v-if="!stockResults.length" class="text-white/30 text-xs py-8 text-center uppercase tracking-widest">
                        На складе ничего не найдено
                    </div>
                </template>
            </div>
        </div>

        <div v-if="lightbox" class="fixed inset-0 z-[80] bg-black/85 flex items-center justify-center p-4"
             @click.self="closeLightbox">
            <button type="button" class="absolute top-4 right-4 text-white/50 hover:text-white text-2xl leading-none px-3 py-1"
                    @click="closeLightbox">×</button>
            <div class="max-w-4xl w-full max-h-[90vh] flex flex-col items-center gap-3" @click.stop>
                <img :src="lightbox.url" :alt="lightbox.title" class="max-w-full max-h-[80vh] object-contain rounded-2xl border border-white/10 bg-black" />
                <div v-if="lightbox.title" class="text-xs text-white/50 text-center uppercase tracking-widest">{{ lightbox.title }}</div>
            </div>
        </div>
    </AdminLayout>
</template>
