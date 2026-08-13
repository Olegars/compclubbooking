<script setup lang="ts">
import { computed, ref, watch } from 'vue'
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
    catalogStats: { products: number, categories: number, synced_at?: string | null }
}>()

const page = usePage()
const flashSuccess = computed(() => (page.props as any).flash?.success || '')
const flashError = computed(() => (page.props as any).flash?.error || '')

const money = (v: any) => Number(v || 0).toLocaleString('ru-RU') + ' ₽'

const expanded = ref<Record<number, boolean>>({})
const isOpen = (id: number) => !!expanded.value[id]
const toggle = (id: number) => { expanded.value = { ...expanded.value, [id]: !expanded.value[id] } }

const showForm = ref(false)
const editingId = ref<number | null>(null)
const submitting = ref(false)
const catalogBusy = ref(false)

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
})

const addLine = () => form.items.push(emptyLine())
const removeLine = (i: number) => {
    const line = form.items[i]
    if (line && ['ordered', 'received', 'from_stock'].includes(line.status)) return
    if (form.items.length > 1) form.items.splice(i, 1)
}

const lineLocked = (line: EstimateLine) =>
    ['ordered', 'received', 'from_stock'].includes(line.status)

const openCreate = () => {
    editingId.value = null
    form.reset()
    form.store_client_id = null
    form.title = ''
    form.notes = ''
    form.items = [emptyLine()]
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
        }))
        : [emptyLine()]
    showForm.value = true
}

const closeForm = () => {
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

const syncCatalog = () => {
    if (!confirm('Скачать каталог поставщика? Может занять время.')) return
    catalogBusy.value = true
    router.post('/admin/store/estimates/sync-catalog', {}, {
        preserveScroll: true,
        onFinish: () => { catalogBusy.value = false },
    })
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

// --- поиск в каталоге поставщика ---
const searchQ = ref('')
const searchResults = ref<any[]>([])
const searchMeta = ref<{ type?: string | null, type_filter_empty?: boolean, count?: number }>({})
const searchLineIndex = ref<number | null>(null)
const searchTimer = ref<any>(null)

watch(searchQ, (q) => {
    clearTimeout(searchTimer.value)
    if (!q || q.trim().length < 2) {
        searchResults.value = []
        searchMeta.value = {}
        return
    }
    searchTimer.value = setTimeout(async () => {
        try {
            const line = searchLineIndex.value !== null ? form.items[searchLineIndex.value] : null
            const type = line?.type || ''
            const params = new URLSearchParams({ q: q.trim() })
            if (type) params.set('type', type)
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
    }, 300)
})

const openSearchFor = (index: number) => {
    searchLineIndex.value = index
    const line = form.items[index]
    // Не подставлять плейсхолдер — только реальное part/name
    const seed = (line?.part || line?.name || '').trim()
    searchQ.value = seed
    searchResults.value = []
    searchMeta.value = {}
}

const pickProduct = (p: any) => {
    if (searchLineIndex.value === null) return
    const line = form.items[searchLineIndex.value]
    if (!line || lineLocked(line)) return
    line.name = p.name
    line.part = p.part || ''
    line.supplier_sku = p.sku
    line.supplier_part = p.part || ''
    line.supplier_name = p.name
    line.supplier_price = p.rrp != null ? Number(p.rrp) : null
    line.status = 'to_order'
    searchLineIndex.value = null
    searchResults.value = []
    searchQ.value = ''
}

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
                    <button v-if="canManage && quickfoxConfigured" type="button" :disabled="catalogBusy"
                            class="px-4 py-3 border border-white/15 text-white/70 font-black uppercase tracking-widest text-[10px] rounded-2xl disabled:opacity-40"
                            @click="syncCatalog">
                        {{ catalogBusy ? 'Синк…' : 'Синк каталога' }}
                    </button>
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
                    <span v-if="catalogStats.synced_at"> · {{ String(catalogStats.synced_at).slice(0, 16).replace('T', ' ') }}</span>
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
                                 class="grid grid-cols-1 md:grid-cols-[1fr_auto] gap-2 text-xs text-white/50 border border-white/5 rounded-xl p-3">
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

        <div v-if="showForm" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 p-4 overflow-y-auto" @click.self="closeForm">
            <form class="bg-[#0a0a0a] border border-white/10 rounded-3xl p-8 w-full max-w-3xl space-y-4 my-8" @submit.prevent="save">
                <h3 class="font-black uppercase italic text-xl">
                    {{ editingId ? `Смета #${editingId}` : 'Новая смета' }}
                </h3>
                <input v-model="form.title" placeholder="Название (сборка / клиент)"
                       class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />
                <select v-model="form.store_client_id" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm">
                    <option :value="null">Без клиента</option>
                    <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }} · {{ c.phone }}</option>
                </select>

                <div class="text-[10px] uppercase font-black text-white/30 tracking-widest">Комплектация</div>
                <div v-for="(line, i) in form.items" :key="i" class="space-y-2 border border-white/5 rounded-2xl p-3">
                    <div class="grid grid-cols-[120px_1fr_40px] gap-2">
                        <select v-model="line.type" class="bg-black border border-white/10 rounded-xl px-2 py-2 text-sm" :disabled="lineLocked(line)">
                            <option :value="null">Тип</option>
                            <option v-for="(label, key) in componentTypes" :key="key" :value="key">{{ label }}</option>
                        </select>
                        <input v-model="line.name" required placeholder="Название" :disabled="lineLocked(line)"
                               class="bg-black border border-white/10 rounded-xl px-3 py-2 text-sm disabled:opacity-50" />
                        <button type="button" class="text-red-400" :disabled="lineLocked(line)" @click="removeLine(i)">×</button>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
                        <input v-model="line.part" placeholder="Part / 13400F" :disabled="lineLocked(line)"
                               class="bg-black border border-white/10 rounded-xl px-3 py-2 text-sm disabled:opacity-50" />
                        <input v-model.number="line.sale_price" type="number" step="0.01" min="0" placeholder="Цена продажи"
                               class="bg-black border border-white/10 rounded-xl px-3 py-2 text-sm" :disabled="lineLocked(line)" />
                        <div class="flex gap-2">
                            <button type="button" class="flex-1 text-[10px] uppercase font-black text-cyan-400 border border-cyan-500/20 rounded-xl"
                                    :disabled="lineLocked(line) || !catalogStats.products"
                                    @click="openSearchFor(i)">
                                Из каталога API
                            </button>
                        </div>
                    </div>
                    <div v-if="line.supplier_sku" class="text-[10px] text-white/30 uppercase tracking-widest">
                        SKU {{ line.supplier_sku }}
                        <span v-if="line.supplier_price != null"> · {{ money(line.supplier_price) }}</span>
                        · {{ itemStatusLabels[line.status] || line.status }}
                    </div>
                </div>
                <button type="button" class="text-[10px] uppercase font-black text-amber-400" @click="addLine">+ позиция</button>

                <div v-if="searchLineIndex !== null" class="border border-cyan-500/20 rounded-2xl p-4 space-y-2 bg-black/40">
                    <div class="flex justify-between items-center">
                        <div class="text-[10px] uppercase font-black text-cyan-400 tracking-widest">
                            Поиск каталога
                            <span v-if="form.items[searchLineIndex]?.type" class="text-white/40">
                                · {{ componentTypes[form.items[searchLineIndex].type] || form.items[searchLineIndex].type }}
                            </span>
                        </div>
                        <button type="button" class="text-white/40 text-xs" @click="searchLineIndex = null">закрыть</button>
                    </div>
                    <p v-if="!form.items[searchLineIndex]?.type" class="text-[10px] text-orange-400/80 uppercase tracking-widest">
                        Выберите тип позиции — поиск сузится по категориям ITP
                    </p>
                    <input v-model="searchQ" placeholder="13400F / Ryzen / точный sku…"
                           class="w-full bg-black border border-white/10 rounded-xl px-3 py-2 text-sm" />
                    <div class="max-h-48 overflow-y-auto space-y-1">
                        <button v-for="p in searchResults" :key="p.sku" type="button"
                                class="w-full text-left px-3 py-2 rounded-lg hover:bg-white/5 text-xs"
                                @click="pickProduct(p)">
                            <span class="text-white/80">{{ p.name }}</span>
                            <span class="text-white/30"> · {{ p.part || '—' }} · sku {{ p.sku }}</span>
                            <span v-if="p.category_name" class="text-cyan-400/50"> · {{ p.category_name }}</span>
                            <span v-if="p.rrp != null" class="text-amber-400/70"> · RRP {{ money(p.rrp) }}</span>
                        </button>
                        <div v-if="searchQ.length >= 2 && !searchResults.length" class="text-white/30 text-xs py-2">
                            Ничего не найдено
                            <span v-if="searchMeta.type_filter_empty"> (категории для типа не сматчились — попробуйте другой запрос)</span>
                        </div>
                    </div>
                </div>

                <textarea v-model="form.notes" placeholder="Заметки" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" rows="2" />
                <div class="flex gap-3 justify-end">
                    <button type="button" class="px-4 py-3 text-[10px] uppercase font-black text-white/40" @click="closeForm">Отмена</button>
                    <button class="px-6 py-3 bg-amber-500 text-black text-[10px] uppercase font-black rounded-xl" :disabled="submitting">
                        {{ editingId ? 'Сохранить' : 'Создать' }}
                    </button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
